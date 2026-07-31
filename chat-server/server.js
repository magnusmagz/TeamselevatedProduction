/**
 * TeamsElevated Chat Server
 * Real-time messaging with team chat, 1:1 DMs, and group conversations
 * Uses Socket.IO for all users (coaches, admins, parents)
 */

require('dotenv').config();
const { Server } = require('socket.io');
const { createServer } = require('http');
const jwt = require('jsonwebtoken');
const { Pool } = require('pg');
const {
  buildConversationsQuery,
  ARCHIVE_SQL,
  UNARCHIVE_SQL,
  UNARCHIVE_ON_NEW_MESSAGE_SQL,
  MARK_READ_SQL,
} = require('./lib/archive');
const { ALLOWED_PARTICIPANTS_SQL, disallowedParticipants } = require('./lib/participants');
const {
  TOMBSTONE_TEXT,
  canModerate,
  isPlatformRole,
  buildMessageHistoryQuery,
  MESSAGE_SCOPE_SQL,
  REMOVE_MESSAGE_SQL,
} = require('./lib/moderation');
const { logInTransaction, socketIp, socketUserAgent } = require('./lib/audit');
const {
  severityForReason,
  isValidReason,
  FILE_USER_REPORT_SQL,
  REPORT_SCOPE_SQL,
} = require('./lib/reports');

// Configuration
const PORT = process.env.PORT || 5001;
const JWT_SECRET = process.env.JWT_SECRET;
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || 'http://localhost:5173,http://localhost:3000,https://teamselevated.netlify.app').split(',');

// Database connection
const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: process.env.NODE_ENV === 'production' ? { rejectUnauthorized: false } : false
});

pool.query('SELECT NOW()')
  .then(() => console.log('Database connected successfully'))
  .catch(err => console.error('Database connection error:', err.message));

// Create HTTP server and Socket.IO instance
const httpServer = createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', timestamp: new Date().toISOString() }));
    return;
  }
  res.writeHead(404);
  res.end('Not found');
});

const io = new Server(httpServer, {
  cors: {
    origin: ALLOWED_ORIGINS,
    methods: ['GET', 'POST'],
    credentials: true
  },
  transports: ['websocket', 'polling']
});

// Store connected users: Map<socketId, { userId, userName, email, role, clubId }>
const connectedUsers = new Map();

// Typing users: Map<conversationId, Map<username, timestamp>>
const typingUsers = new Map();

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Verify JWT token and extract user info
 */
function verifyToken(token) {
  try {
    if (!JWT_SECRET) {
      console.error('JWT_SECRET not configured');
      return null;
    }
    return jwt.verify(token, JWT_SECRET, { algorithms: ['HS256'] });
  } catch (error) {
    console.error('JWT verification failed:', error.message);
    return null;
  }
}

/**
 * Get the user's primary role string from JWT payload
 */
function getUserRole(payload) {
  if (payload.system_role === 'super_admin') return 'super_admin';

  if (payload.active_context && payload.active_context.role) {
    return payload.active_context.role;
  }

  if (payload.roles && Array.isArray(payload.roles)) {
    const prioritized = ['owner', 'club_admin', 'admin', 'coach', 'parent', 'player'];
    for (const p of prioritized) {
      if (payload.roles.some(r => r.role === p)) return p;
    }
  }

  return 'member';
}

/**
 * Check if the role can initiate new DM/group conversations
 */
function canInitiateConversation(role) {
  return ['super_admin', 'owner', 'club_admin', 'admin', 'coach', 'parent'].includes(role);
}

/**
 * Get the club ID from the JWT payload
 */
function getClubId(payload) {
  if (payload.active_context && payload.active_context.scope_type === 'club') {
    return payload.active_context.scope_id;
  }
  if (payload.roles && payload.roles.length > 0) {
    const clubRole = payload.roles.find(r => r.scope_type === 'club');
    if (clubRole) return clubRole.scope_id;
    // For team-scoped roles, look up the club
    return payload.roles[0].scope_id;
  }
  return null;
}

/**
 * Get team IDs that a parent has access to via the guardian chain:
 * users.email → guardians.email → athlete_guardians → athletes → team_members
 */
async function getParentTeamIds(userId) {
  const query = `
    SELECT DISTINCT tm.team_id
    FROM users u
    JOIN guardians g ON g.email = u.email
    JOIN athlete_guardians ag ON ag.guardian_id = g.id
    JOIN athletes a ON a.id = ag.athlete_id
    JOIN team_members tm ON tm.athlete_id = a.id
    WHERE u.id = $1
  `;
  try {
    const result = await pool.query(query, [userId]);
    return result.rows.map(r => r.team_id);
  } catch (error) {
    console.error('Error getting parent team IDs:', error.message);
    return [];
  }
}

/**
 * Get team IDs for a coach/admin based on their JWT roles
 */
function getCoachTeamIds(payload) {
  if (!payload.roles) return [];
  return payload.roles
    .filter(r => r.scope_type === 'team')
    .map(r => r.scope_id);
}

/**
 * Ensure a team conversation exists. Creates one if it doesn't.
 * Returns the conversation ID.
 */
async function ensureTeamConversation(teamId, clubId) {
  // Try to find existing
  const existing = await pool.query(
    `SELECT id FROM conversations WHERE type = 'team' AND team_id = $1`,
    [teamId]
  );
  if (existing.rows.length > 0) return existing.rows[0].id;

  // Create new — use ON CONFLICT for concurrency safety
  const result = await pool.query(`
    INSERT INTO conversations (type, team_id, club_id)
    VALUES ('team', $1, $2)
    ON CONFLICT DO NOTHING
    RETURNING id
  `, [teamId, clubId]);

  if (result.rows.length > 0) return result.rows[0].id;

  // Race condition: another connection created it first
  const retry = await pool.query(
    `SELECT id FROM conversations WHERE type = 'team' AND team_id = $1`,
    [teamId]
  );
  return retry.rows[0]?.id;
}

/**
 * Team ids this user can reach: their own teams, plus every team in their club
 * if they hold a club-level role.
 *
 * Extracted from getUserConversations so the conversation list and the
 * create-conversation allowlist cannot drift apart — if these two disagreed, a
 * user could be offered someone they are then refused permission to message, or
 * worse, the reverse.
 */
async function getAccessibleTeamIds(userId, role, payload) {
  if (role === 'parent') return await getParentTeamIds(userId);

  let teamIds = getCoachTeamIds(payload);
  if (canInitiateConversation(role)) {
    const clubId = getClubId(payload);
    if (clubId) {
      try {
        const clubTeams = await pool.query(`SELECT id FROM teams WHERE club_id = $1`, [clubId]);
        teamIds = [...new Set([...teamIds, ...clubTeams.rows.map(r => r.id)])];
      } catch (e) {
        console.error('Error fetching club teams:', e.message);
      }
    }
  }
  return teamIds;
}

/**
 * Get all conversations for a user. Includes:
 * - Conversations where they're an explicit participant
 * - Team conversations they belong to (auto-discovered for parents)
 *
 * Archived conversations are excluded by default. Pass { archived: true } for the
 * user's archived list — archive is per-user view state, never deletion, so the
 * same rows are simply on the other side of the filter.
 */
async function getUserConversations(userId, role, payload, { archived = false } = {}) {
  const teamIds = await getAccessibleTeamIds(userId, role, payload);

  // Ensure team conversations exist
  const clubId = getClubId(payload);
  for (const tid of teamIds) {
    if (clubId) {
      await ensureTeamConversation(tid, clubId);
    }
  }

  // Get conversations where user is explicit participant OR is part of a team
  // conversation, minus (or only) the ones this user archived.
  const query = buildConversationsQuery({ archived });

  try {
    const result = await pool.query(query, [userId, teamIds]);
    const conversations = result.rows;

    // For each conversation, get participants and unread count
    for (const conv of conversations) {
      // Get participants
      const participants = await pool.query(`
        SELECT cp.user_id AS "userId", cp.display_name AS "displayName", cp.role
        FROM conversation_participants cp
        WHERE cp.conversation_id = $1 AND cp.left_at IS NULL
      `, [conv.id]);
      conv.participants = participants.rows;

      // Get unread count
      const readReceipt = await pool.query(`
        SELECT last_read_message_id FROM conversation_participants
        WHERE conversation_id = $1 AND user_id = $2
      `, [conv.id, userId]);

      const lastReadId = readReceipt.rows[0]?.last_read_message_id || 0;

      const unread = await pool.query(`
        SELECT COUNT(*) as count FROM chat_messages
        WHERE conversation_id = $1 AND id > $2 AND sender_id != $3 AND deleted_at IS NULL
      `, [conv.id, lastReadId, userId]);
      conv.unreadCount = parseInt(unread.rows[0].count, 10);

      // Compute display name
      if (conv.type === 'team') {
        conv.displayName = conv.teamName || 'Team Chat';
      } else if (conv.type === 'direct') {
        const other = conv.participants.find(p => p.userId !== userId);
        conv.displayName = other?.displayName || 'Direct Message';
      } else {
        const others = conv.participants
          .filter(p => p.userId !== userId)
          .map(p => p.displayName)
          .filter(Boolean);
        conv.displayName = others.length > 0 ? others.join(', ') : 'Group Chat';
      }

      // Last message info
      if (conv.lastMessagePreview) {
        const lastMsg = await pool.query(`
          SELECT sender_name, created_at FROM chat_messages
          WHERE conversation_id = $1 AND deleted_at IS NULL
          ORDER BY created_at DESC LIMIT 1
        `, [conv.id]);
        if (lastMsg.rows[0]) {
          conv.lastMessage = {
            text: conv.lastMessagePreview,
            timestamp: lastMsg.rows[0].created_at,
            senderName: lastMsg.rows[0].sender_name
          };
        }
      }
    }

    return conversations;
  } catch (error) {
    console.error('Error loading conversations:', error.message);
    return [];
  }
}

/**
 * Load message history for a conversation, including legacy messages for team conversations
 */
async function loadConversationMessages(conversationId, teamId, limit = 50) {
  // Removed messages come back as tombstones rather than being filtered out —
  // see lib/moderation.js. A message that simply vanishes leaves participants
  // unsure whether they imagined it.
  const query = buildMessageHistoryQuery({ team: Boolean(teamId) });
  const params = teamId ? [conversationId, teamId, limit] : [conversationId, limit];

  try {
    const result = await pool.query(query, params);
    return result.rows.reverse(); // chronological order
  } catch (error) {
    console.error('Error loading conversation messages:', error.message);
    return [];
  }
}

/**
 * Save a message to a conversation
 */
async function saveConversationMessage(conversationId, senderId, senderName, senderRole, text) {
  const query = `
    INSERT INTO chat_messages (conversation_id, scope_type, scope_id, channel, message_text, sender_id, sender_name, sender_role)
    VALUES ($1, 'team', 0, 'general', $2, $3, $4, $5)
    RETURNING id, created_at
  `;
  try {
    const result = await pool.query(query, [conversationId, text, senderId, senderName, senderRole]);
    // Update conversation's last message
    const preview = text.substring(0, 100);
    await pool.query(`
      UPDATE conversations SET last_message_at = $1, last_message_preview = $2 WHERE id = $3
    `, [result.rows[0].created_at, preview, conversationId]);

    return result.rows[0];
  } catch (error) {
    console.error('Error saving message:', error.message);
    return null;
  }
}

/**
 * Check if a user is a participant in a conversation (or has team access)
 */
async function isConversationParticipant(conversationId, userId, role, payload) {
  // Check explicit participation
  const explicit = await pool.query(
    `SELECT 1 FROM conversation_participants WHERE conversation_id = $1 AND user_id = $2 AND left_at IS NULL`,
    [conversationId, userId]
  );
  if (explicit.rows.length > 0) return true;

  // Check team-based access
  const conv = await pool.query(
    `SELECT type, team_id FROM conversations WHERE id = $1`,
    [conversationId]
  );
  if (conv.rows.length === 0) return false;
  if (conv.rows[0].type !== 'team' || !conv.rows[0].team_id) return false;

  const teamId = conv.rows[0].team_id;
  if (role === 'parent') {
    const parentTeams = await getParentTeamIds(userId);
    return parentTeams.includes(teamId);
  } else {
    const coachTeams = getCoachTeamIds(payload);
    if (coachTeams.includes(teamId)) return true;
    // Club-level admins have access to all teams in their club
    if (canInitiateConversation(role)) {
      const clubId = getClubId(payload);
      if (clubId) {
        const teamClub = await pool.query(`SELECT club_id FROM teams WHERE id = $1`, [teamId]);
        return teamClub.rows[0]?.club_id === clubId;
      }
    }
  }
  return false;
}

/**
 * Get team members (coaches + parents) for the participant picker.
 * Parents are discovered via the guardian chain.
 * Coaches are found via conversation participation or prior messages.
 */
async function getTeamMembersForPicker(teamId) {
  // Get parents via guardian chain
  const parents = await pool.query(`
    SELECT DISTINCT u.id AS "userId", CONCAT(u.first_name, ' ', u.last_name) AS "displayName", 'parent' AS role
    FROM users u
    JOIN guardians g ON g.email = u.email
    JOIN athlete_guardians ag ON ag.guardian_id = g.id
    JOIN athletes a ON a.id = ag.athlete_id
    JOIN team_members tm ON tm.athlete_id = a.id
    WHERE tm.team_id = $1
  `, [teamId]);

  // Get coaches/admins who have participated in this team's conversations or sent messages
  const coaches = await pool.query(`
    SELECT DISTINCT u.id AS "userId", CONCAT(u.first_name, ' ', u.last_name) AS "displayName", 'coach' AS role
    FROM users u
    WHERE u.id IN (
      SELECT user_id FROM conversation_participants cp
      JOIN conversations c ON c.id = cp.conversation_id
      WHERE c.team_id = $1
    )
    OR u.id IN (
      SELECT DISTINCT sender_id FROM chat_messages
      WHERE scope_type = 'team' AND scope_id = $1
    )
  `, [teamId]);

  // Merge and deduplicate (parents take priority for role assignment)
  const seen = new Set();
  const unique = [];

  // Add parents first so they get the 'parent' role
  for (const m of parents.rows) {
    if (!seen.has(m.userId)) {
      seen.add(m.userId);
      unique.push(m);
    }
  }

  // Add coaches that aren't already listed as parents
  for (const m of coaches.rows) {
    if (!seen.has(m.userId)) {
      seen.add(m.userId);
      unique.push(m);
    }
  }

  return unique;
}

/**
 * Room name for a conversation
 */
function getConversationRoom(conversationId) {
  return `conversation-${conversationId}`;
}

// ─── Socket.IO Connection Handling ────────────────────────────────────────────

io.on('connection', (socket) => {
  console.log(`Socket connected: ${socket.id}`);

  // ─── Authentication ───────────────────────────────────────────────────────
  socket.on('authenticate', async (data) => {
    const { token } = data;

    if (!token) {
      socket.emit('authError', { message: 'Missing authentication token' });
      return;
    }

    const payload = verifyToken(token);
    if (!payload) {
      socket.emit('authError', { message: 'Invalid or expired token' });
      return;
    }

    const role = getUserRole(payload);
    const clubId = getClubId(payload);

    // Store user info (no scope needed — conversations handle that)
    const userInfo = {
      userId: payload.user_id,
      userName: payload.name,
      email: payload.email,
      role,
      clubId,
      payload // keep full payload for permission checks
    };
    connectedUsers.set(socket.id, userInfo);

    console.log(`User ${payload.name} (${role}) authenticated`);

    socket.emit('authSuccess', {
      message: 'Authentication successful',
      user: {
        id: payload.user_id,
        name: payload.name,
        email: payload.email,
        role,
        canCreate: canInitiateConversation(role)
      }
    });
  });

  // ─── Load Conversations ───────────────────────────────────────────────────
  socket.on('loadConversations', async () => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    try {
      const conversations = await getUserConversations(
        userInfo.userId, userInfo.role, userInfo.payload
      );
      socket.emit('conversationsList', conversations);
    } catch (error) {
      console.error('Error loading conversations:', error.message);
      socket.emit('error', { message: 'Failed to load conversations' });
    }
  });

  // ─── Join Conversation ────────────────────────────────────────────────────
  socket.on('joinConversation', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    const { conversationId } = data;

    // Verify access
    const hasAccess = await isConversationParticipant(
      conversationId, userInfo.userId, userInfo.role, userInfo.payload
    );
    if (!hasAccess) {
      socket.emit('error', { message: 'You do not have access to this conversation' });
      return;
    }

    // Join the Socket.IO room
    const room = getConversationRoom(conversationId);
    socket.join(room);

    // Get conversation info (need team_id for legacy message loading)
    const convInfo = await pool.query(
      `SELECT team_id FROM conversations WHERE id = $1`,
      [conversationId]
    );
    const teamId = convInfo.rows[0]?.team_id;

    // Load and send message history
    const messages = await loadConversationMessages(conversationId, teamId);
    socket.emit('messageHistory', { conversationId, messages });

    // Update last_read
    await pool.query(`
      UPDATE conversation_participants SET last_read_at = NOW(),
        last_read_message_id = (SELECT MAX(id) FROM chat_messages WHERE conversation_id = $1 AND deleted_at IS NULL)
      WHERE conversation_id = $1 AND user_id = $2
    `, [conversationId, userInfo.userId]);
  });

  // ─── Leave Conversation Room ──────────────────────────────────────────────
  socket.on('leaveConversation', (data) => {
    const { conversationId } = data;
    const room = getConversationRoom(conversationId);
    socket.leave(room);
  });

  // ─── Create Conversation ──────────────────────────────────────────────────
  socket.on('createConversation', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    if (!canInitiateConversation(userInfo.role)) {
      socket.emit('error', { message: 'Only coaches and admins can create conversations' });
      return;
    }

    const { participantIds, type } = data;
    if (!participantIds || !Array.isArray(participantIds) || participantIds.length === 0) {
      socket.emit('error', { message: 'At least one participant is required' });
      return;
    }

    const convType = participantIds.length === 1 ? 'direct' : (type || 'group');

    // ─── Participant allowlist ────────────────────────────────────────────────
    // Until 2026-07-30 this handler inserted whatever ids the client sent, so any
    // initiator could open a DM with any user in any club — and coaches could
    // reach athletes, which the product forbids. The set is built from guardians
    // and club staff; athletes are excluded by never being in it.
    //
    // NEVER "improve" this by subtracting athletes.user_id instead: 23 of the 26
    // populated values point at a GUARDIAN's account and 10 at staff accounts, so
    // a blocklist would refuse the coach↔crew DMs this feature exists for. See
    // lib/participants.js.
    try {
      const teamIds = await getAccessibleTeamIds(
        userInfo.userId, userInfo.role, userInfo.payload
      );
      const allowed = await pool.query(ALLOWED_PARTICIPANTS_SQL, [
        teamIds,
        getClubId(userInfo.payload),
      ]);
      const refused = disallowedParticipants(
        participantIds,
        allowed.rows.map(r => r.user_id),
        userInfo.userId
      );
      if (refused.length > 0) {
        console.warn(
          `Refused conversation: user ${userInfo.userId} (${userInfo.role}) requested ` +
          `out-of-scope participants ${refused.join(',')}`
        );
        socket.emit('error', {
          message: 'You can only message coaches and crew connected to your teams',
        });
        return;
      }
    } catch (error) {
      // Fail CLOSED. If the allowlist cannot be computed we do not know who the
      // creator may talk to, and guessing "everyone" is how this was broken.
      console.error('Error resolving allowed participants:', error.message);
      socket.emit('error', { message: 'Failed to create conversation' });
      return;
    }

    try {
      // Check if a conversation with the exact same participant set already exists
      // (covers both DMs and groups). Narrow to conversations the REQUESTER is already
      // in — dedupe is only meaningful for chats they participate in, and this avoids
      // a tablescan over every conversation in the system.
      const allParticipantIds = Array.from(new Set([userInfo.userId, ...participantIds])).sort((a, b) => a - b);
      const existingConv = await pool.query(`
        SELECT c.id FROM conversations c
        WHERE c.type = $1
          AND c.id IN (
            SELECT conversation_id FROM conversation_participants
            WHERE user_id = $3 AND left_at IS NULL
          )
          AND (
            SELECT ARRAY(
              SELECT user_id FROM conversation_participants
              WHERE conversation_id = c.id AND left_at IS NULL
              ORDER BY user_id
            )
          ) = $2::int[]
        LIMIT 1
      `, [convType, allParticipantIds, userInfo.userId]);

      if (existingConv.rows.length > 0) {
        const convId = existingConv.rows[0].id;
        const conversations = await getUserConversations(
          userInfo.userId, userInfo.role, userInfo.payload
        );
        const existing = conversations.find(c => c.id === convId);
        socket.emit('conversationCreated', existing || { id: convId });
        return;
      }

      // Create the conversation
      const result = await pool.query(`
        INSERT INTO conversations (type, club_id, created_by)
        VALUES ($1, $2, $3)
        RETURNING id, created_at
      `, [convType, userInfo.clubId, userInfo.userId]);

      const conversationId = result.rows[0].id;

      // Add creator as participant
      const creatorName = userInfo.userName || userInfo.email;
      await pool.query(`
        INSERT INTO conversation_participants (conversation_id, user_id, role, display_name)
        VALUES ($1, $2, 'creator', $3)
      `, [conversationId, userInfo.userId, creatorName]);

      // Batch-fetch participant names + batch-insert in a single round-trip each.
      // Previously this loop did 2 queries per participant — for a 100-person group
      // that was 200+ DB round-trips and made create feel unresponsive.
      if (participantIds.length > 0) {
        const namesRes = await pool.query(
          `SELECT id, first_name, last_name, email FROM users WHERE id = ANY($1::int[])`,
          [participantIds]
        );
        const nameById = new Map();
        for (const r of namesRes.rows) {
          nameById.set(r.id, `${r.first_name || ''} ${r.last_name || ''}`.trim() || r.email || 'Unknown');
        }
        const orderedIds = participantIds.map(Number);
        const orderedNames = orderedIds.map(id => nameById.get(id) || 'Unknown');
        await pool.query(`
          INSERT INTO conversation_participants (conversation_id, user_id, role, display_name)
          SELECT $1, uid, 'member', dn
          FROM UNNEST($2::int[], $3::text[]) AS t(uid, dn)
          ON CONFLICT (conversation_id, user_id) DO NOTHING
        `, [conversationId, orderedIds, orderedNames]);
      }

      // Get the full conversation object
      const conversations = await getUserConversations(
        userInfo.userId, userInfo.role, userInfo.payload
      );
      const newConv = conversations.find(c => c.id === conversationId);

      // Notify creator
      socket.emit('conversationCreated', newConv || { id: conversationId, type: convType });

      // Notify other participants
      for (const pid of participantIds) {
        // Find their socket(s)
        for (const [sid, info] of connectedUsers.entries()) {
          if (info.userId === pid && sid !== socket.id) {
            io.to(sid).emit('newConversation', newConv || { id: conversationId, type: convType });
          }
        }
      }

    } catch (error) {
      console.error('Error creating conversation:', error.message);
      socket.emit('error', { message: 'Failed to create conversation' });
    }
  });

  // ─── Send Message ─────────────────────────────────────────────────────────
  socket.on('sendMessage', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    const { conversationId, text } = data;
    if (!conversationId || !text || !text.trim()) {
      socket.emit('error', { message: 'Conversation ID and message text are required' });
      return;
    }

    // Verify access
    const hasAccess = await isConversationParticipant(
      conversationId, userInfo.userId, userInfo.role, userInfo.payload
    );
    if (!hasAccess) {
      socket.emit('error', { message: 'You do not have access to this conversation' });
      return;
    }

    // Save message
    const saved = await saveConversationMessage(
      conversationId,
      userInfo.userId,
      userInfo.userName || userInfo.email,
      userInfo.role,
      text.trim()
    );

    if (!saved) {
      socket.emit('error', { message: 'Failed to save message' });
      return;
    }

    const message = {
      id: saved.id.toString(),
      conversationId,
      text: text.trim(),
      sender: userInfo.userName || userInfo.email,
      senderId: userInfo.userId,
      role: userInfo.role,
      timestamp: saved.created_at,
      time: new Date(saved.created_at).toLocaleTimeString('en-US', {
        hour: '2-digit', minute: '2-digit', hour12: false
      })
    };

    // Broadcast to room
    const room = getConversationRoom(conversationId);
    io.to(room).emit('receiveMessage', message);

    // Notify all participants with updated conversation info (for list preview)
    const preview = text.trim().substring(0, 100);
    const updatePayload = {
      conversationId,
      lastMessage: {
        text: preview,
        timestamp: saved.created_at,
        senderName: userInfo.userName || userInfo.email
      }
    };

    // A new message un-archives the conversation for everyone who had archived it.
    // This is what makes archive safe to offer: nothing is ever permanently hidden,
    // so the control never has to promise more than it delivers.
    let unarchivedUserIds = [];
    try {
      const unarchived = await pool.query(UNARCHIVE_ON_NEW_MESSAGE_SQL, [conversationId]);
      unarchivedUserIds = unarchived.rows.map(r => r.user_id);
    } catch (error) {
      console.error('Error un-archiving on new message:', error.message);
    }

    // Broadcast to all sockets of participants not in the room
    // (they need list updates even if not viewing this conversation)
    const participants = await pool.query(
      `SELECT user_id FROM conversation_participants WHERE conversation_id = $1 AND left_at IS NULL`,
      [conversationId]
    );

    // Also include team members for team conversations
    const convInfo = await pool.query(
      `SELECT type, team_id FROM conversations WHERE id = $1`,
      [conversationId]
    );

    const participantUserIds = new Set(participants.rows.map(r => r.user_id));

    for (const [sid, info] of connectedUsers.entries()) {
      if (participantUserIds.has(info.userId) || (convInfo.rows[0]?.type === 'team')) {
        io.to(sid).emit('conversationUpdated', updatePayload);
      }
    }

    // `conversationUpdated` is not enough for anyone we just un-archived: the
    // conversation is absent from their client-side list, so the handler maps over
    // a list that does not contain it and silently drops the update. Push the whole
    // conversation instead — `newConversation` already dedupes by id.
    if (unarchivedUserIds.length > 0) {
      const unarchivedSet = new Set(unarchivedUserIds);
      for (const [sid, info] of connectedUsers.entries()) {
        if (!unarchivedSet.has(info.userId)) continue;
        try {
          const convs = await getUserConversations(info.userId, info.role, info.payload);
          const restored = convs.find(c => c.id === conversationId);
          if (restored) io.to(sid).emit('newConversation', restored);
        } catch (e) {
          console.error('Error restoring un-archived conversation:', e.message);
        }
      }
    }

    // Clear typing indicator
    const typing = typingUsers.get(conversationId);
    if (typing) {
      typing.delete(userInfo.userName);
    }
  });

  // ─── Get Team Members ─────────────────────────────────────────────────────
  socket.on('getTeamMembers', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    if (!canInitiateConversation(userInfo.role)) {
      socket.emit('error', { message: 'Only coaches and admins can view team members' });
      return;
    }

    const { teamId } = data;
    try {
      const members = await getTeamMembersForPicker(teamId);
      // Filter out the requesting user
      const filtered = members.filter(m => m.userId !== userInfo.userId);
      socket.emit('teamMembers', { teamId, members: filtered });
    } catch (error) {
      console.error('Error getting team members:', error.message);
      socket.emit('error', { message: 'Failed to load team members' });
    }
  });

  // ─── Typing Indicator ─────────────────────────────────────────────────────
  socket.on('typing', (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    const { conversationId, isTyping } = data;
    if (!conversationId) return;

    if (!typingUsers.has(conversationId)) {
      typingUsers.set(conversationId, new Map());
    }

    const typing = typingUsers.get(conversationId);
    const username = userInfo.userName || userInfo.email;

    if (isTyping) {
      typing.set(username, Date.now());
    } else {
      typing.delete(username);
    }

    // Clean up stale typing indicators (older than 5 seconds)
    const now = Date.now();
    for (const [u, ts] of typing.entries()) {
      if (now - ts > 5000) typing.delete(u);
    }

    // Broadcast typing status to conversation room
    const room = getConversationRoom(conversationId);
    io.to(room).emit('typingUpdate', {
      conversationId,
      typingUsers: Array.from(typing.keys()).map(u => ({
        username: u,
        timestamp: typing.get(u)
      }))
    });
  });

  // ─── Reactions (kept for backward compatibility) ──────────────────────────
  socket.on('addReaction', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    const { messageId, emoji } = data;
    try {
      await pool.query(`
        INSERT INTO chat_reactions (message_id, user_id, emoji)
        VALUES ($1, $2, $3)
        ON CONFLICT (message_id, user_id, emoji) DO NOTHING
      `, [messageId, userInfo.userId, emoji]);

      // Find conversation for this message
      const msg = await pool.query(`SELECT conversation_id FROM chat_messages WHERE id = $1`, [messageId]);
      if (msg.rows[0]?.conversation_id) {
        const room = getConversationRoom(msg.rows[0].conversation_id);
        io.to(room).emit('reactionAdded', { messageId, emoji, userId: userInfo.userId });
      }
    } catch (error) {
      console.error('Error adding reaction:', error.message);
    }
  });

  socket.on('removeReaction', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    const { messageId, emoji } = data;
    try {
      await pool.query(`
        DELETE FROM chat_reactions WHERE message_id = $1 AND user_id = $2 AND emoji = $3
      `, [messageId, userInfo.userId, emoji]);

      const msg = await pool.query(`SELECT conversation_id FROM chat_messages WHERE id = $1`, [messageId]);
      if (msg.rows[0]?.conversation_id) {
        const room = getConversationRoom(msg.rows[0].conversation_id);
        io.to(room).emit('reactionRemoved', { messageId, emoji, userId: userInfo.userId });
      }
    } catch (error) {
      console.error('Error removing reaction:', error.message);
    }
  });

  // ─── Mark Read ────────────────────────────────────────────────────────────
  socket.on('markRead', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    const { conversationId } = data;
    try {
      await pool.query(MARK_READ_SQL, [
        conversationId,
        userInfo.userId,
        userInfo.userName || userInfo.email,
      ]);
    } catch (error) {
      console.error('Error marking read:', error.message);
    }
  });

  // ─── Report a message ─────────────────────────────────────────────────────
  // Any participant may report. The report is both a queue item and, from M3
  // onward, the record that authorises a club admin to read the conversation.
  socket.on('reportMessage', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    const { messageId, reason, note } = data || {};
    if (!messageId || !isValidReason(reason)) {
      socket.emit('error', { message: 'A message and a reason are required' });
      return;
    }

    try {
      const scope = await pool.query(REPORT_SCOPE_SQL, [messageId]);
      const msg = scope.rows[0];
      if (!msg) {
        socket.emit('error', { message: 'Message not found' });
        return;
      }

      // Reporting must not become a way to probe for messages elsewhere: you can
      // only report something you can already read.
      const hasAccess = await isConversationParticipant(
        msg.conversationId, userInfo.userId, userInfo.role, userInfo.payload
      );
      if (!hasAccess) {
        socket.emit('error', { message: 'You do not have access to this conversation' });
        return;
      }

      await pool.query(FILE_USER_REPORT_SQL, [
        messageId,
        msg.conversationId,
        msg.clubId || getClubId(userInfo.payload),
        userInfo.userId,
        reason,
        (note || '').slice(0, 2000) || null,
        severityForReason(reason),
      ]);

      // Reported either way, including when this was a duplicate. The reporter
      // must not learn whether someone else already flagged it, or whether an
      // admin has dismissed it.
      socket.emit('messageReported', { messageId });
    } catch (error) {
      console.error('Error reporting message:', error.message);
      socket.emit('error', { message: 'Failed to report message' });
    }
  });

  // ─── Moderation removal ───────────────────────────────────────────────────
  // The ONLY way a message is ever removed. Club admins only — not coaches, and
  // not senders removing their own words, which is the capability deliberately
  // withheld. Soft delete: the row survives, everyone sees a tombstone, and the
  // text stays recoverable until retention purges it.
  socket.on('removeMessage', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    if (!canModerate(userInfo.role)) {
      socket.emit('error', { message: 'Only club administrators can remove messages' });
      return;
    }

    const { messageId, reason } = data || {};
    if (!messageId) {
      socket.emit('error', { message: 'Message ID is required' });
      return;
    }

    const client = await pool.connect();
    try {
      const scope = await client.query(MESSAGE_SCOPE_SQL, [messageId]);
      const msg = scope.rows[0];
      if (!msg) {
        socket.emit('error', { message: 'Message not found' });
        return;
      }

      // A club admin is confined to their own club; platform roles are not.
      if (!isPlatformRole(userInfo.role)) {
        const actorClub = getClubId(userInfo.payload);
        if (!actorClub || !msg.clubId || Number(actorClub) !== Number(msg.clubId)) {
          socket.emit('error', { message: 'You do not have access to this conversation' });
          return;
        }
      }

      if (msg.deletedAt) {
        // Already removed. Idempotent, and must not rewrite who removed it.
        socket.emit('messageRemoved', {
          messageId, conversationId: msg.conversationId, text: TOMBSTONE_TEXT,
        });
        return;
      }

      await client.query('BEGIN');
      const removed = await client.query(REMOVE_MESSAGE_SQL, [
        messageId, userInfo.userId, reason || null,
      ]);
      if (removed.rowCount === 0) {
        // Raced with another admin.
        await client.query('ROLLBACK');
        socket.emit('messageRemoved', {
          messageId, conversationId: msg.conversationId, text: TOMBSTONE_TEXT,
        });
        return;
      }

      // Audit INSIDE the transaction: unlike AuditLogger's swallow-and-continue,
      // a removal that cannot be recorded must not happen. The entry deliberately
      // does NOT carry the message text — audit_log is retained for 2555 days
      // against the message's own 90, so copying it there would defeat removals
      // motivated by privacy rather than safety.
      await logInTransaction(client, {
        userId: userInfo.userId,
        action: 'chat_message_removed',
        resourceType: 'chat_messages',
        resourceId: Number(messageId),
        ipAddress: socketIp(socket),
        userAgent: socketUserAgent(socket),
        details: {
          conversation_id: msg.conversationId,
          sender_id: msg.senderId,
          reason: reason || null,
          actor_role: userInfo.role,
        },
      });
      await client.query('COMMIT');

      // Everyone in the room swaps the message for a tombstone live.
      io.to(getConversationRoom(msg.conversationId)).emit('messageRemoved', {
        messageId,
        conversationId: msg.conversationId,
        text: TOMBSTONE_TEXT,
      });
    } catch (error) {
      try { await client.query('ROLLBACK'); } catch (_) { /* not in a transaction */ }
      console.error('Error removing message:', error.message);
      socket.emit('error', { message: 'Failed to remove message' });
    } finally {
      client.release();
    }
  });

  // ─── Archive / Unarchive ──────────────────────────────────────────────────
  // Archive is per-user view state. It hides the conversation from THIS user's
  // list and nothing else — no other participant is affected, no message is
  // touched, and the next message brings it back. There is deliberately no
  // user-facing delete: a control labelled "delete" that soft-deletes would tell
  // the user their message is gone when it is not, which in a product carrying
  // minors' communications is the liability, not the fix.
  socket.on('archiveConversation', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    const { conversationId } = data || {};
    if (!conversationId) {
      socket.emit('error', { message: 'Conversation ID is required' });
      return;
    }

    // You cannot archive a conversation you cannot see.
    const hasAccess = await isConversationParticipant(
      conversationId, userInfo.userId, userInfo.role, userInfo.payload
    );
    if (!hasAccess) {
      socket.emit('error', { message: 'You do not have access to this conversation' });
      return;
    }

    try {
      await pool.query(ARCHIVE_SQL, [
        conversationId,
        userInfo.userId,
        userInfo.userName || userInfo.email,
      ]);
      socket.emit('conversationArchived', { conversationId });
    } catch (error) {
      console.error('Error archiving conversation:', error.message);
      socket.emit('error', { message: 'Failed to archive conversation' });
    }
  });

  socket.on('unarchiveConversation', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    const { conversationId } = data || {};
    if (!conversationId) {
      socket.emit('error', { message: 'Conversation ID is required' });
      return;
    }

    const hasAccess = await isConversationParticipant(
      conversationId, userInfo.userId, userInfo.role, userInfo.payload
    );
    if (!hasAccess) {
      socket.emit('error', { message: 'You do not have access to this conversation' });
      return;
    }

    try {
      await pool.query(UNARCHIVE_SQL, [conversationId, userInfo.userId]);
      const conversations = await getUserConversations(
        userInfo.userId, userInfo.role, userInfo.payload
      );
      const restored = conversations.find(c => c.id === conversationId);
      socket.emit('conversationUnarchived', { conversationId, conversation: restored || null });
    } catch (error) {
      console.error('Error unarchiving conversation:', error.message);
      socket.emit('error', { message: 'Failed to unarchive conversation' });
    }
  });

  socket.on('loadArchivedConversations', async () => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    try {
      const conversations = await getUserConversations(
        userInfo.userId, userInfo.role, userInfo.payload, { archived: true }
      );
      socket.emit('archivedConversationsList', conversations);
    } catch (error) {
      console.error('Error loading archived conversations:', error.message);
      socket.emit('error', { message: 'Failed to load archived conversations' });
    }
  });

  // ─── Disconnect ───────────────────────────────────────────────────────────
  socket.on('disconnect', () => {
    const userInfo = connectedUsers.get(socket.id);
    if (userInfo) {
      console.log(`User ${userInfo.userName} disconnected`);

      // Remove from typing indicators
      for (const [convId, typing] of typingUsers.entries()) {
        const username = userInfo.userName || userInfo.email;
        if (typing.has(username)) {
          typing.delete(username);
          const room = getConversationRoom(convId);
          io.to(room).emit('typingUpdate', {
            conversationId: convId,
            typingUsers: Array.from(typing.keys()).map(u => ({
              username: u,
              timestamp: typing.get(u)
            }))
          });
        }
      }
    }

    connectedUsers.delete(socket.id);
    console.log(`Socket disconnected: ${socket.id}`);
  });
});

// Start server
httpServer.listen(PORT, () => {
  console.log(`Chat server running on port ${PORT}`);
  console.log(`Allowed origins: ${ALLOWED_ORIGINS.join(', ')}`);
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('SIGTERM received, shutting down...');
  httpServer.close(() => {
    pool.end();
    process.exit(0);
  });
});
