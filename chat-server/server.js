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
 * Get all conversations for a user. Includes:
 * - Conversations where they're an explicit participant
 * - Team conversations they belong to (auto-discovered for parents)
 */
async function getUserConversations(userId, role, payload) {
  // Get team IDs this user has access to
  let teamIds = [];
  if (role === 'parent') {
    teamIds = await getParentTeamIds(userId);
  } else {
    teamIds = getCoachTeamIds(payload);
    // Also include club-level: all teams in their club
    if (canInitiateConversation(role)) {
      const clubId = getClubId(payload);
      if (clubId) {
        try {
          const clubTeams = await pool.query(
            `SELECT id FROM teams WHERE club_id = $1`,
            [clubId]
          );
          const clubTeamIds = clubTeams.rows.map(r => r.id);
          teamIds = [...new Set([...teamIds, ...clubTeamIds])];
        } catch (e) {
          console.error('Error fetching club teams:', e.message);
        }
      }
    }
  }

  // Ensure team conversations exist
  const clubId = getClubId(payload);
  for (const tid of teamIds) {
    if (clubId) {
      await ensureTeamConversation(tid, clubId);
    }
  }

  // Get conversations where user is explicit participant OR is part of a team conversation
  const query = `
    SELECT DISTINCT
      c.id,
      c.type,
      c.team_id AS "teamId",
      t.name AS "teamName",
      c.last_message_at AS "lastMessageAt",
      c.last_message_preview AS "lastMessagePreview",
      c.created_at AS "createdAt",
      COALESCE(c.last_message_at, c.created_at) AS "sortTime"
    FROM conversations c
    LEFT JOIN teams t ON t.id = c.team_id
    LEFT JOIN conversation_participants cp ON cp.conversation_id = c.id AND cp.user_id = $1
    WHERE (
      cp.user_id IS NOT NULL AND cp.left_at IS NULL
    )
    OR (
      c.type = 'team' AND c.team_id = ANY($2::int[])
    )
    ORDER BY "sortTime" DESC
  `;

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
  let query;
  let params;

  if (teamId) {
    // Team conversation: UNION with legacy messages that have no conversation_id
    query = `
      SELECT id, message_text AS text, sender_name AS sender, sender_id AS "senderId",
             sender_role AS role, $1::int AS "conversationId",
             created_at AS timestamp,
             TO_CHAR(created_at, 'HH24:MI') AS time
      FROM chat_messages
      WHERE conversation_id = $1 AND deleted_at IS NULL
      UNION ALL
      SELECT id, message_text AS text, sender_name AS sender, sender_id AS "senderId",
             sender_role AS role, $1::int AS "conversationId",
             created_at AS timestamp,
             TO_CHAR(created_at, 'HH24:MI') AS time
      FROM chat_messages
      WHERE scope_type = 'team' AND scope_id = $2 AND conversation_id IS NULL AND deleted_at IS NULL
      ORDER BY timestamp DESC
      LIMIT $3
    `;
    params = [conversationId, teamId, limit];
  } else {
    query = `
      SELECT id, message_text AS text, sender_name AS sender, sender_id AS "senderId",
             sender_role AS role, conversation_id AS "conversationId",
             created_at AS timestamp,
             TO_CHAR(created_at, 'HH24:MI') AS time
      FROM chat_messages
      WHERE conversation_id = $1 AND deleted_at IS NULL
      ORDER BY created_at DESC
      LIMIT $2
    `;
    params = [conversationId, limit];
  }

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
      await pool.query(`
        UPDATE conversation_participants SET last_read_at = NOW(),
          last_read_message_id = (SELECT MAX(id) FROM chat_messages WHERE conversation_id = $1 AND deleted_at IS NULL)
        WHERE conversation_id = $1 AND user_id = $2
      `, [conversationId, userInfo.userId]);
    } catch (error) {
      console.error('Error marking read:', error.message);
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
