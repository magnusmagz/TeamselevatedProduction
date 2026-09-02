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
const { GUARDIAN_JOIN_SQL } = require('./lib/guardian_identity');
const {
  expandsToWholeClub,
  COACH_TEAM_IDS_SQL,
  GUARDIAN_TEAM_IDS_SQL,
  CLUB_TEAM_IDS_SQL,
  mergeTeamIds,
} = require('./lib/team_scope');
const {
  TOMBSTONE_TEXT,
  canModerate,
  isPlatformRole,
  buildMessageHistoryQuery,
  MESSAGE_SCOPE_SQL,
  REMOVE_MESSAGE_SQL,
} = require('./lib/moderation');
const {
  REACTION_EMOJI,
  isAllowedEmoji,
  REACTIONS_FOR_MESSAGES_SQL,
  groupReactions,
} = require('./lib/reactions');
const {
  canCreatePoll,
  validatePoll,
  buildPollView,
  POLL_FOR_MESSAGES_SQL,
  OPTIONS_WITH_VOTES_SQL,
} = require('./lib/polls');
const {
  canPinMessage,
  UNPIN_CONVERSATION_SQL,
  PIN_MESSAGE_SQL,
  UNPIN_MESSAGE_SQL,
  PINNED_FOR_CONVERSATION_SQL,
  buildPinnedView,
} = require('./lib/pinning');
const { logInTransaction, socketIp, socketUserAgent } = require('./lib/audit');
const {
  OPEN_REPORT_FOR_CONVERSATION_SQL,
  LOG_ACCESS_SQL,
  moderatorMayOpen,
} = require('./lib/access');
const {
  severityForReason,
  isValidReason,
  FILE_USER_REPORT_SQL,
  FILE_AUTO_REPORT_SQL,
  REPORT_SCOPE_SQL,
} = require('./lib/reports');
const { evaluateMessage } = require('./lib/flags');

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
 * users → guardians → athlete_guardians → athletes → team_members
 *
 * The first hop is lib/guardian_identity.js — user_guardians links UNION the
 * case-insensitive email match — not a bare email comparison.
 */
async function getParentTeamIds(userId) {
  try {
    const result = await pool.query(GUARDIAN_TEAM_IDS_SQL, [userId]);
    return result.rows.map(r => r.id);
  } catch (error) {
    console.error('Error getting parent team IDs:', error.message);
    return [];
  }
}

/**
 * Teams this user actually coaches, read from the DATABASE.
 *
 * This used to filter `payload.roles` for `scope_type === 'team'`. Our tokens
 * never carry one — every role is minted club-scoped — so it returned [] for
 * every user, and the club-wide fallback in getAccessibleTeamIds silently became
 * every coach's entire team list. See lib/team_scope.js.
 *
 * On error this returns [] rather than throwing: the callers treat an empty
 * scope as "no team access", which is the safe direction for a permission
 * lookup. A DB blip must never widen what someone can see.
 */
async function getCoachTeamIds(userId) {
  try {
    const result = await pool.query(COACH_TEAM_IDS_SQL, [userId]);
    return result.rows.map(r => r.id);
  } catch (error) {
    console.error('Error getting coach team IDs:', error.message);
    return [];
  }
}

/**
 * Ensure a team conversation exists. Creates one if it doesn't.
 * Returns the conversation ID.
 */
async function ensureTeamConversation(teamId) {
  // Try to find existing
  const existing = await pool.query(
    `SELECT id FROM conversations WHERE type = 'team' AND team_id = $1`,
    [teamId]
  );
  if (existing.rows.length > 0) return existing.rows[0].id;

  // The club comes from the TEAM, not from whoever happened to trigger this.
  // It used to be passed in from the caller's own club, which was harmless only
  // because the caller's team list was built by club in the first place. Once
  // coaches are scoped to the teams they actually coach, a coach can hold a team
  // whose club_id differs from theirs — two live teams have club_id NULL — and
  // the old form would have stamped the coach's club onto that team's
  // conversation. Moderation and reporting are club-scoped, so that is an
  // invented association, not a cosmetic one.
  const result = await pool.query(`
    INSERT INTO conversations (type, team_id, club_id)
    VALUES ('team', $1, (SELECT club_id FROM teams WHERE id = $1))
    ON CONFLICT DO NOTHING
    RETURNING id
  `, [teamId]);

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
  // Club-level staff see the whole club. `expandsToWholeClub` is NOT
  // `canInitiateConversation` — the latter includes `coach` and `parent`, and
  // using it here is what gave every CKU coach all 16 teams.
  if (expandsToWholeClub(role)) {
    const clubId = getClubId(payload);
    if (!clubId) return [];
    try {
      const clubTeams = await pool.query(CLUB_TEAM_IDS_SQL, [clubId]);
      return clubTeams.rows.map(r => r.id);
    } catch (e) {
      console.error('Error fetching club teams:', e.message);
      return [];
    }
  }

  // Everyone else gets the teams they are actually attached to. Both lookups run
  // for everyone: getUserRole() collapses a user to ONE role and prefers coach
  // over parent, so a coach who is also a parent would otherwise lose their own
  // child's team chat. Six CKU coaches are in that position.
  const [coachTeams, guardianTeams] = await Promise.all([
    getCoachTeamIds(userId),
    getParentTeamIds(userId),
  ]);

  return mergeTeamIds(coachTeams, guardianTeams);
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

  // Ensure team conversations exist. No longer gated on the viewer having a
  // club: the conversation belongs to the team, and a coach with no active club
  // context used to silently get no conversations created at all.
  for (const tid of teamIds) {
    await ensureTeamConversation(tid);
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
async function loadConversationMessages(conversationId, teamId, limit = 50, viewerId = null) {
  // Removed messages come back as tombstones rather than being filtered out —
  // see lib/moderation.js. A message that simply vanishes leaves participants
  // unsure whether they imagined it.
  const query = buildMessageHistoryQuery({ team: Boolean(teamId) });
  const params = teamId ? [conversationId, teamId, limit] : [conversationId, limit];

  try {
    const result = await pool.query(query, params);
    const messages = result.rows.reverse(); // chronological order

    // ⚠️ THE HALF THAT WAS MISSING. Reactions were storable and broadcastable
    // since January, but never sent with history — so even a stored reaction
    // disappeared on refresh, which is part of why the feature never worked.
    await attachReactions(messages);
    await attachPolls(messages, viewerId);

    return messages;
  } catch (error) {
    console.error('Error loading conversation messages:', error.message);
    return [];
  }
}

/**
 * Tell everyone in a conversation what is pinned now, or that nothing is.
 *
 * Sent to the room rather than per viewer: a pin is the same for everybody,
 * unlike a poll where youVoted and hidden results differ by who is looking.
 */
async function broadcastPinned(conversationId) {
  try {
    const result = await pool.query(PINNED_FOR_CONVERSATION_SQL, [conversationId]);
    io.to(getConversationRoom(conversationId)).emit('pinnedChanged', {
      conversationId,
      pinned: buildPinnedView(result.rows[0]),
    });
  } catch (error) {
    console.error('Error broadcasting pin:', error.message);
  }
}

/**
 * Push a poll's current state to everyone in the conversation.
 *
 * ⚠️ Rendered PER VIEWER, not once. `youVoted` and the hide-results-until-you-
 * vote rule both depend on who is looking, and an anonymous poll must never send
 * voter identities to anyone. One shared payload would leak the second and get
 * the first wrong.
 *
 * `extra` carries the message envelope when the poll is brand new, so clients
 * can append it; on a vote there is no new message and it is omitted.
 */
async function broadcastPoll(conversationId, messageId, extra) {
  try {
    const polls = await pool.query(POLL_FOR_MESSAGES_SQL, [[Number(messageId)]]);
    const poll = polls.rows[0];
    if (!poll) return;

    const optionRows = await pool.query(OPTIONS_WITH_VOTES_SQL, [[poll.id]]);

    const room = getConversationRoom(conversationId);
    const sockets = await io.in(room).fetchSockets();

    for (const s of sockets) {
      const info = connectedUsers.get(s.id);
      const view = buildPollView(poll, optionRows.rows, info ? info.userId : null);
      s.emit(extra ? 'pollCreated' : 'pollUpdated', {
        ...(extra || {}),
        messageId: String(messageId),
        conversationId,
        poll: view,
      });
    }
  } catch (error) {
    console.error('Error broadcasting poll:', error.message);
  }
}

/**
 * Hang each poll off its message, in place, rendered for THIS viewer.
 *
 * Per-viewer because `youVoted` and the results-hidden-until-you-vote rule are
 * both about who is looking. Same failure policy as reactions: a broken poll
 * query must not cost the conversation.
 */
async function attachPolls(messages, viewerId) {
  const ids = (messages || [])
    .filter((m) => m.messageType === 'poll')
    .map((m) => Number(m.id))
    .filter((n) => Number.isInteger(n) && n > 0);

  if (ids.length === 0) return;

  try {
    const polls = await pool.query(POLL_FOR_MESSAGES_SQL, [ids]);
    if (polls.rows.length === 0) return;

    const optionRows = await pool.query(
      OPTIONS_WITH_VOTES_SQL, [polls.rows.map((p) => p.id)]
    );

    const byMessage = new Map();
    for (const poll of polls.rows) {
      const forPoll = optionRows.rows.filter((o) => o.pollId === poll.id);
      byMessage.set(String(poll.messageId), buildPollView(poll, forPoll, viewerId));
    }

    for (const message of messages) {
      const view = byMessage.get(String(message.id));
      if (view) message.poll = view;
    }
  } catch (error) {
    console.error('Error loading polls:', error.message);
  }
}

/**
 * Hang each message's reactions off it, in place.
 *
 * A failure here must NOT cost the messages. Reactions are decoration on top of
 * the conversation; losing the conversation because a decoration query failed
 * would be a far worse trade, so this logs and leaves the messages bare.
 */
async function attachReactions(messages) {
  const ids = (messages || [])
    .map((m) => Number(m.id))
    .filter((n) => Number.isInteger(n) && n > 0);

  if (ids.length === 0) return;

  try {
    const result = await pool.query(REACTIONS_FOR_MESSAGES_SQL, [ids]);
    const byMessage = groupReactions(result.rows);

    for (const message of messages) {
      message.reactions = byMessage[String(message.id)] || [];
    }
  } catch (error) {
    console.error('Error loading reactions:', error.message);
    for (const message of messages) {
      if (!message.reactions) message.reactions = [];
    }
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
  const conv = await pool.query(
    `SELECT type, team_id FROM conversations WHERE id = $1`,
    [conversationId]
  );
  if (conv.rows.length === 0) return false;

  // For a DM or group, the participant row IS the membership.
  if (conv.rows[0].type !== 'team' || !conv.rows[0].team_id) {
    const explicit = await pool.query(
      `SELECT 1 FROM conversation_participants WHERE conversation_id = $1 AND user_id = $2 AND left_at IS NULL`,
      [conversationId, userId]
    );
    return explicit.rows.length > 0;
  }

  // For a TEAM conversation, team scope is the only authority — the explicit
  // check deliberately does NOT run. markRead and archive UPSERT a participant
  // row, so simply opening a team chat leaves one behind permanently; checking
  // it first meant anyone who had already browsed another team's chat kept
  // access after being scoped out. The row is per-user state, not a grant.
  const teamId = Number(conv.rows[0].team_id);

  // Delegate to the ONE scope function rather than re-deriving it here. The old
  // inline copy had its own club-wide branch gated on canInitiateConversation,
  // so a coach could join and read any team conversation in their club — the
  // listing bug and this one were the same mistake written twice. Keeping a
  // single source means a future scope change cannot fix the list and miss the
  // door.
  const teamIds = await getAccessibleTeamIds(userId, role, payload);
  return teamIds.includes(teamId);
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
    ${GUARDIAN_JOIN_SQL}
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
    let hasAccess = await isConversationParticipant(
      conversationId, userInfo.userId, userInfo.role, userInfo.payload
    );

    // Flag-gated moderator read. A club admin may open a conversation they are
    // not part of ONLY because an open report exists on it — there is no
    // browse-any-conversation path. Note this grants READING: sendMessage still
    // uses the strict predicate above, so an admin cannot post into a DM between
    // two other people.
    let viaReportId = null;
    if (!hasAccess && canModerate(userInfo.role)) {
      try {
        const found = await pool.query(OPEN_REPORT_FOR_CONVERSATION_SQL, [conversationId]);
        const report = found.rows[0];
        if (report && moderatorMayOpen({
          role: userInfo.role,
          actorClubId: getClubId(userInfo.payload),
          reportClubId: report.clubId,
          isPlatform: isPlatformRole(userInfo.role),
        })) {
          hasAccess = true;
          viaReportId = report.id;
        }
      } catch (e) {
        console.error('Error resolving moderator access:', e.message);
      }
    }

    if (!hasAccess) {
      socket.emit('error', { message: 'You do not have access to this conversation' });
      return;
    }

    // Record the open BEFORE serving any of it. A read that happened without a
    // log entry is exactly what this table exists to make impossible, so if the
    // log write fails the conversation is not served.
    if (viaReportId !== null) {
      try {
        await pool.query(LOG_ACCESS_SQL, [
          userInfo.userId, conversationId, viaReportId, getClubId(userInfo.payload),
        ]);
      } catch (e) {
        console.error('Error writing chat access log:', e.message);
        socket.emit('error', { message: 'Failed to open conversation' });
        return;
      }
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
    const messages = await loadConversationMessages(conversationId, teamId, 50, userInfo.userId);
    socket.emit('messageHistory', { conversationId, messages });

    // The pin belongs with the history: it is what someone should see first on
    // opening a conversation, and fetching it separately would flash the
    // conversation without it.
    try {
      const pinned = await pool.query(PINNED_FOR_CONVERSATION_SQL, [conversationId]);
      socket.emit('pinnedChanged', {
        conversationId,
        pinned: buildPinnedView(pinned.rows[0]),
      });
    } catch (error) {
      console.error('Error loading pinned message:', error.message);
    }

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
      //
      // String-compared for the same reason as the conversationUpdated loop:
      // info.userId is a STRING off the JWT, while participantIds arrive as
      // numbers from the client. `===` there means a newly created conversation
      // never appears for the other person until they refresh.
      for (const pid of participantIds) {
        // Find their socket(s)
        for (const [sid, info] of connectedUsers.entries()) {
          if (String(info.userId) === String(pid) && sid !== socket.id) {
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
      // Who sent it. Carried so the client can increment its unread badge
      // WITHOUT counting the recipient's own message.
      //
      // Recipients who do not have this conversation open never receive
      // `receiveMessage` — they get this event instead — and the client handler
      // used to update only the preview. So the unread badge could never
      // increment live for exactly the people the badge exists for, and was
      // correct only on a fresh page load. Reported 2026-08-26.
      senderId: userInfo.userId,
      lastMessage: {
        text: preview,
        timestamp: saved.created_at,
        senderName: userInfo.userName || userInfo.email
      }
    };

    // ─── Automatic flagging ───────────────────────────────────────────────────
    // Runs AFTER the message is saved and broadcast, inside its own try/catch,
    // and cannot alter or block delivery. Nothing is censored — a flag only adds
    // a queue item. Moderation must never become a way for chat to stop working.
    try {
      const hits = evaluateMessage(text.trim());
      if (hits.length > 0) {
        // Only pay for this lookup when something actually fired. The report is
        // filed against the CONVERSATION's club, not the sender's, so it lands in
        // the right admin's queue even when those differ.
        const cc = await pool.query('SELECT club_id FROM conversations WHERE id = $1', [conversationId]);
        const flagClubId = cc.rows[0]?.club_id ?? getClubId(userInfo.payload);

        for (const hit of hits) {
          await pool.query(FILE_AUTO_REPORT_SQL, [
            saved.id, conversationId, flagClubId, hit.rule, hit.severity,
          ]);
        }
      }
    } catch (error) {
      console.error('Error auto-flagging message:', error.message);
    }

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

    // ⚠️ Compared as STRINGS on both sides, and that is load-bearing.
    //
    // pg returns int4 as a JavaScript NUMBER, while info.userId comes from the
    // JWT, which lib/JWT.php mints as `(string)$userId`. So `Set{74}.has("74")`
    // is false, and for a DIRECT conversation nobody matched — the event reached
    // no one. Team chats kept working only because of the `type === 'team'`
    // fallback below, which is why this looked like "the parent portal is fine,
    // the staff app is broken": parents live in team chats, staff were testing a
    // DM.
    //
    // Symptom was an unread badge that never moved until a page refresh.
    // Reported 2026-08-26. Third instance of this same string/number class in one
    // day — see sameUser() on the client.
    const participantUserIds = new Set(participants.rows.map(r => String(r.user_id)));

    for (const [sid, info] of connectedUsers.entries()) {
      if (participantUserIds.has(String(info.userId)) || (convInfo.rows[0]?.type === 'team')) {
        io.to(sid).emit('conversationUpdated', updatePayload);
      }
    }

    // `conversationUpdated` is not enough for anyone we just un-archived: the
    // conversation is absent from their client-side list, so the handler maps over
    // a list that does not contain it and silently drops the update. Push the whole
    // conversation instead — `newConversation` already dedupes by id.
    if (unarchivedUserIds.length > 0) {
      // Same string/number trap as the participant set above.
      const unarchivedSet = new Set(unarchivedUserIds.map(String));
      for (const [sid, info] of connectedUsers.entries()) {
        if (!unarchivedSet.has(String(info.userId))) continue;
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

    // The teamId comes from the client, so role alone is not a gate — a coach
    // could ask for any team's roster, including teams in other clubs. Bound
    // what the endpoint ACCEPTS, not what the UI happens to send.
    const accessible = await getAccessibleTeamIds(
      userInfo.userId, userInfo.role, userInfo.payload
    );
    if (!accessible.includes(Number(teamId))) {
      socket.emit('error', { message: 'You do not have access to that team' });
      return;
    }

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
  // ─── Pinning ──────────────────────────────────────────────────────────────

  socket.on('pinMessage', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    // ⚠️ NOT canModerate() — that excludes coaches, and a coach pinning their
    // own team's practice details is the main thing this is for.
    if (!canPinMessage(userInfo.role)) {
      socket.emit('error', { message: 'Only coaches and club administrators can pin a message' });
      return;
    }

    const { messageId } = data || {};
    if (!messageId) return;

    const client = await pool.connect();
    try {
      const found = await client.query(
        'SELECT conversation_id, deleted_at FROM chat_messages WHERE id = $1', [messageId]
      );
      const message = found.rows[0];
      if (!message || !message.conversation_id) {
        socket.emit('error', { message: 'That message no longer exists' });
        return;
      }
      if (message.deleted_at) {
        socket.emit('error', { message: 'A removed message cannot be pinned' });
        return;
      }

      const allowed = await isConversationParticipant(
        message.conversation_id, userInfo.userId, userInfo.role, userInfo.payload
      );
      if (!allowed) {
        socket.emit('error', { message: 'You do not have access to this conversation' });
        return;
      }

      await client.query('BEGIN');
      // Clear the old pin first — one per conversation, and the partial unique
      // index would otherwise reject this as a conflict rather than a replace.
      await client.query(UNPIN_CONVERSATION_SQL, [message.conversation_id]);
      await client.query(PIN_MESSAGE_SQL, [messageId, userInfo.userId]);
      await client.query('COMMIT');

      await broadcastPinned(message.conversation_id);
    } catch (error) {
      await client.query('ROLLBACK').catch(() => {});
      console.error('Error pinning message:', error.message);
      socket.emit('error', { message: 'Could not pin that message' });
    } finally {
      client.release();
    }
  });

  socket.on('unpinMessage', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    if (!canPinMessage(userInfo.role)) {
      socket.emit('error', { message: 'Only coaches and club administrators can unpin a message' });
      return;
    }

    const { messageId } = data || {};
    if (!messageId) return;

    try {
      const found = await pool.query(
        'SELECT conversation_id FROM chat_messages WHERE id = $1', [messageId]
      );
      const conversationId = found.rows[0] && found.rows[0].conversation_id;
      if (!conversationId) return;

      const allowed = await isConversationParticipant(
        conversationId, userInfo.userId, userInfo.role, userInfo.payload
      );
      if (!allowed) {
        socket.emit('error', { message: 'You do not have access to this conversation' });
        return;
      }

      await pool.query(UNPIN_MESSAGE_SQL, [messageId]);
      await broadcastPinned(conversationId);
    } catch (error) {
      console.error('Error unpinning message:', error.message);
    }
  });

  // ─── Polls ────────────────────────────────────────────────────────────────
  //
  // A poll IS a chat_message with message_type 'poll', so it inherits
  // notifications, moderation and archiving without any of them knowing about
  // polls. That is the point of the discriminator.

  socket.on('createPoll', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    // ⚠️ Its OWN predicate. canModerate() excludes coaches and
    // canInitiateConversation() includes parents — neither list is right here.
    if (!canCreatePoll(userInfo.role)) {
      socket.emit('error', { message: 'Only coaches and club administrators can create a poll' });
      return;
    }

    const { conversationId } = data || {};
    if (!conversationId) {
      socket.emit('error', { message: 'Conversation is required' });
      return;
    }

    const allowed = await isConversationParticipant(
      conversationId, userInfo.userId, userInfo.role, userInfo.payload
    );
    if (!allowed) {
      socket.emit('error', { message: 'You do not have access to this conversation' });
      return;
    }

    const check = validatePoll(data);
    if (!check.ok) {
      socket.emit('error', { message: check.error });
      return;
    }
    const poll = check.poll;

    const client = await pool.connect();
    try {
      await client.query('BEGIN');

      // The message carries the question as its text, so every surface that
      // shows a message preview — the conversation list, the notification
      // digest, a search — reads sensibly without knowing what a poll is.
      // scope_type / scope_id / channel are NOT NULL legacy columns from the
      // pre-conversation model, and saveConversationMessage sets the same
      // placeholder values. Omitting them fails the insert outright, which is
      // exactly what happened on first use.
      const saved = await client.query(`
        INSERT INTO chat_messages
          (conversation_id, scope_type, scope_id, channel,
           message_text, sender_id, sender_name, sender_role, message_type)
        VALUES ($1, 'team', 0, 'general', $2, $3, $4, $5, 'poll')
        RETURNING id, created_at
      `, [conversationId, poll.question, userInfo.userId,
          userInfo.userName || userInfo.email, userInfo.role]);

      const messageId = saved.rows[0].id;

      const savedPoll = await client.query(`
        INSERT INTO chat_polls
          (message_id, question, is_anonymous, results_before_vote, allow_multiple, closes_at, created_by)
        VALUES ($1, $2, $3, $4, $5, $6, $7)
        RETURNING id
      `, [messageId, poll.question, poll.isAnonymous, poll.resultsBeforeVote,
          poll.allowMultiple, poll.closesAt, userInfo.userId]);

      const pollId = savedPoll.rows[0].id;

      for (let i = 0; i < poll.options.length; i++) {
        await client.query(
          'INSERT INTO chat_poll_options (poll_id, label, sort_order) VALUES ($1, $2, $3)',
          [pollId, poll.options[i], i]
        );
      }

      // A poll is a message, so the conversation list has to reflect it — an
      // unsorted conversation with a stale preview reads as "nothing happened".
      await client.query(
        'UPDATE conversations SET last_message_at = $1, last_message_preview = $2 WHERE id = $3',
        [saved.rows[0].created_at, poll.question.substring(0, 100), conversationId]
      );

      await client.query('COMMIT');

      await broadcastPoll(conversationId, messageId, {
        id: messageId.toString(),
        conversationId,
        text: poll.question,
        messageType: 'poll',
        sender: userInfo.userName || userInfo.email,
        senderId: String(userInfo.userId),
        role: userInfo.role,
        timestamp: saved.rows[0].created_at,
      });
    } catch (error) {
      await client.query('ROLLBACK').catch(() => {});
      console.error('Error creating poll:', error.message);
      socket.emit('error', { message: 'Could not create the poll' });
    } finally {
      client.release();
    }
  });

  socket.on('votePoll', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    const { optionId } = data || {};
    if (!optionId) return;

    try {
      const context = await pool.query(`
        SELECT p.id AS poll_id, p.message_id, p.allow_multiple, p.closes_at,
               m.conversation_id
          FROM chat_poll_options o
          JOIN chat_polls p ON p.id = o.poll_id
          JOIN chat_messages m ON m.id = p.message_id
         WHERE o.id = $1
      `, [optionId]);

      const ctx = context.rows[0];
      if (!ctx) {
        socket.emit('error', { message: 'That poll option no longer exists' });
        return;
      }

      // Closed is a comparison against the deadline, evaluated now — never a
      // stored status some worker has to keep current.
      if (ctx.closes_at && new Date(ctx.closes_at) <= new Date()) {
        socket.emit('error', { message: 'That poll has closed' });
        return;
      }

      const allowed = await isConversationParticipant(
        ctx.conversation_id, userInfo.userId, userInfo.role, userInfo.payload
      );
      if (!allowed) {
        socket.emit('error', { message: 'You do not have access to this conversation' });
        return;
      }

      // Toggle. Voting the same option again withdraws it, which is the only
      // way to un-answer a single-choice poll.
      const existing = await pool.query(
        'SELECT id FROM chat_poll_votes WHERE option_id = $1 AND user_id = $2',
        [optionId, userInfo.userId]
      );

      if (existing.rows.length > 0) {
        await pool.query('DELETE FROM chat_poll_votes WHERE id = $1', [existing.rows[0].id]);
      } else {
        if (!ctx.allow_multiple) {
          // Single choice: replace whatever they picked before, in this poll only.
          await pool.query(`
            DELETE FROM chat_poll_votes
             WHERE user_id = $1
               AND option_id IN (SELECT id FROM chat_poll_options WHERE poll_id = $2)
          `, [userInfo.userId, ctx.poll_id]);
        }
        // ON CONFLICT because two taps on a slow connection is the ordinary
        // case. The UNIQUE constraint is the real guard; this keeps it quiet.
        await pool.query(`
          INSERT INTO chat_poll_votes (option_id, user_id) VALUES ($1, $2)
          ON CONFLICT (option_id, user_id) DO NOTHING
        `, [optionId, userInfo.userId]);
      }

      await broadcastPoll(ctx.conversation_id, ctx.message_id);
    } catch (error) {
      console.error('Error voting:', error.message);
    }
  });

  /** Change the closing time, or reveal results. Creator or a moderator. */
  socket.on('updatePoll', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    const { pollId, closesAt, resultsBeforeVote } = data || {};
    if (!pollId) return;

    try {
      const found = await pool.query(`
        SELECT p.id, p.created_by, p.message_id, m.conversation_id
          FROM chat_polls p JOIN chat_messages m ON m.id = p.message_id
         WHERE p.id = $1
      `, [pollId]);

      const poll = found.rows[0];
      if (!poll) return;

      const isCreator = String(poll.created_by) === String(userInfo.userId);
      if (!isCreator && !canModerate(userInfo.role)) {
        socket.emit('error', { message: 'Only the person who created this poll can change it' });
        return;
      }

      // ⚠️ is_anonymous is deliberately NOT updatable here. Flipping it either
      // way breaks a promise already made to everyone who voted.
      if (closesAt !== undefined) {
        const when = closesAt === null ? null : new Date(closesAt);
        if (when !== null && Number.isNaN(when.getTime())) {
          socket.emit('error', { message: 'That closing time is not a date' });
          return;
        }
        // Shortening past votes already cast is allowed and must NOT discard
        // them — the deadline gates new votes only.
        await pool.query('UPDATE chat_polls SET closes_at = $1 WHERE id = $2',
          [when === null ? null : when.toISOString(), pollId]);
      }

      if (resultsBeforeVote !== undefined) {
        await pool.query('UPDATE chat_polls SET results_before_vote = $1 WHERE id = $2',
          [Boolean(resultsBeforeVote), pollId]);
      }

      await broadcastPoll(poll.conversation_id, poll.message_id);
    } catch (error) {
      console.error('Error updating poll:', error.message);
    }
  });

  socket.on('addReaction', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    const { messageId, emoji } = data || {};

    // Refuse anything outside the agreed six. Migration 079 constrains the
    // column as well, but a rejected INSERT would surface here as a swallowed
    // error and the reaction would silently not appear — checking first means
    // the client is told.
    if (!isAllowedEmoji(emoji)) {
      socket.emit('error', { message: 'That reaction is not available' });
      return;
    }

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
        io.to(room).emit('reactionAdded', {
          // Ids as STRINGS on the wire. The client holds message ids as strings
          // and user ids arrive from the JWT as strings; mixing the two is the
          // mismatch that produced three visible bugs on 2026-08-26.
          messageId: String(messageId),
          emoji,
          userId: String(userInfo.userId),
          // Sent so a client can show WHO reacted without a second round trip.
          userName: userInfo.userName || userInfo.email || 'Someone',
        });
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
        io.to(room).emit('reactionRemoved', {
          messageId: String(messageId),
          emoji,
          userId: String(userInfo.userId),
        });
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
