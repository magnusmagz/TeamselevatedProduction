/**
 * TeamsElevated Chat Server
 * Real-time messaging for coaches and admins using Socket.IO
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

// Test database connection
pool.query('SELECT NOW()')
  .then(() => console.log('Database connected successfully'))
  .catch(err => console.error('Database connection error:', err.message));

// Create HTTP server and Socket.IO instance
const httpServer = createServer((req, res) => {
  // Simple health check endpoint
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

// Store connected users: Map<socketId, { userId, userName, role, scopeType, scopeId }>
const connectedUsers = new Map();

// Typing users: Map<channelKey, Set<username>>
const typingUsers = new Map();

/**
 * Verify JWT token and extract user info
 */
function verifyToken(token) {
  try {
    if (!JWT_SECRET) {
      console.error('JWT_SECRET not configured');
      return null;
    }

    const decoded = jwt.verify(token, JWT_SECRET, { algorithms: ['HS256'] });
    return decoded;
  } catch (error) {
    console.error('JWT verification failed:', error.message);
    return null;
  }
}

/**
 * Check if user is a coach or admin (allowed to use chat)
 */
function isCoachOrAdmin(payload) {
  // Check system role
  if (payload.system_role === 'super_admin') {
    return true;
  }

  // Check organizational roles
  if (payload.roles && Array.isArray(payload.roles)) {
    return payload.roles.some(role =>
      role.role === 'admin' ||
      role.role === 'club_admin' ||
      role.role === 'coach' ||
      role.role === 'owner'
    );
  }

  // Check active context role
  if (payload.active_context && payload.active_context.role) {
    const role = payload.active_context.role;
    return role === 'admin' || role === 'club_admin' || role === 'coach' || role === 'owner';
  }

  return false;
}

/**
 * Get channel key for storing typing users
 */
function getChannelKey(scopeType, scopeId, channel) {
  return `${scopeType}:${scopeId}:${channel}`;
}

/**
 * Get room name for Socket.IO
 */
function getRoomName(scopeType, scopeId, channel = 'general') {
  return `${scopeType}-${scopeId}-${channel}`;
}

/**
 * Save message to database
 */
async function saveMessage(message) {
  const query = `
    INSERT INTO chat_messages (scope_type, scope_id, channel, message_text, sender_id, sender_name, sender_role)
    VALUES ($1, $2, $3, $4, $5, $6, $7)
    RETURNING id, created_at
  `;

  try {
    const result = await pool.query(query, [
      message.scopeType,
      message.scopeId,
      message.channel || 'general',
      message.text,
      message.senderId,
      message.sender,
      message.role || null
    ]);

    return result.rows[0];
  } catch (error) {
    console.error('Error saving message:', error.message);
    return null;
  }
}

/**
 * Load message history for a scope/channel
 */
async function loadMessageHistory(scopeType, scopeId, channel = 'general', limit = 50) {
  const query = `
    SELECT id, message_text as text, sender_name as sender, sender_id as "senderId",
           sender_role as role, channel, scope_type as "scopeType", scope_id as "scopeId",
           created_at as timestamp,
           TO_CHAR(created_at, 'HH24:MI') as time
    FROM chat_messages
    WHERE scope_type = $1 AND scope_id = $2 AND channel = $3 AND deleted_at IS NULL
    ORDER BY created_at DESC
    LIMIT $4
  `;

  try {
    const result = await pool.query(query, [scopeType, scopeId, channel, limit]);
    // Return in chronological order (oldest first)
    return result.rows.reverse();
  } catch (error) {
    console.error('Error loading message history:', error.message);
    return [];
  }
}

// Socket.IO connection handling
io.on('connection', (socket) => {
  console.log(`Socket connected: ${socket.id}`);

  // Handle authentication
  socket.on('authenticate', async (data) => {
    const { token, scopeType, scopeId } = data;

    if (!token || !scopeType || !scopeId) {
      socket.emit('authError', { message: 'Missing required authentication data' });
      return;
    }

    // Verify JWT
    const payload = verifyToken(token);
    if (!payload) {
      socket.emit('authError', { message: 'Invalid or expired token' });
      return;
    }

    // Check if user is coach or admin
    if (!isCoachOrAdmin(payload)) {
      socket.emit('authError', { message: 'Chat is only available for coaches and administrators' });
      return;
    }

    // Verify user has access to this scope
    const hasAccess = payload.roles?.some(role =>
      role.scope_type === scopeType && role.scope_id === parseInt(scopeId)
    );

    if (!hasAccess && payload.system_role !== 'super_admin') {
      socket.emit('authError', { message: 'You do not have access to this chat' });
      return;
    }

    // Get user's role for this scope
    const scopeRole = payload.roles?.find(role =>
      role.scope_type === scopeType && role.scope_id === parseInt(scopeId)
    );

    // Store user info
    const userInfo = {
      oderId: payload.user_id,
      userName: payload.name,
      email: payload.email,
      role: scopeRole?.role || payload.active_context?.role || 'member',
      scopeType,
      scopeId: parseInt(scopeId)
    };
    connectedUsers.set(socket.id, userInfo);

    // Join the room for this scope
    const roomName = getRoomName(scopeType, scopeId);
    socket.join(roomName);

    console.log(`User ${payload.name} joined room ${roomName}`);

    // Send auth success
    socket.emit('authSuccess', {
      message: 'Authentication successful',
      user: {
        id: payload.user_id,
        name: payload.name,
        role: userInfo.role
      }
    });

    // Load and send message history
    const history = await loadMessageHistory(scopeType, scopeId);
    socket.emit('messageHistory', history);
  });

  // Handle new message
  socket.on('sendMessage', async (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) {
      socket.emit('error', { message: 'Not authenticated' });
      return;
    }

    const message = {
      id: data.id || Date.now().toString(),
      text: data.text,
      sender: data.sender || userInfo.userName,
      senderId: data.senderId || userInfo.userId,
      channel: data.channel || 'general',
      role: data.role || userInfo.role,
      scopeType: userInfo.scopeType,
      scopeId: userInfo.scopeId,
      timestamp: new Date().toISOString(),
      time: new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false })
    };

    // Save to database
    const saved = await saveMessage(message);
    if (saved) {
      message.id = saved.id.toString();
      message.timestamp = saved.created_at;
    }

    // Broadcast to room
    const roomName = getRoomName(userInfo.scopeType, userInfo.scopeId, message.channel);
    io.to(getRoomName(userInfo.scopeType, userInfo.scopeId)).emit('receiveMessage', message);

    // Clear typing indicator for this user
    const channelKey = getChannelKey(userInfo.scopeType, userInfo.scopeId, message.channel);
    const typing = typingUsers.get(channelKey);
    if (typing) {
      typing.delete(userInfo.userName);
    }
  });

  // Handle typing indicator
  socket.on('typing', (data) => {
    const userInfo = connectedUsers.get(socket.id);
    if (!userInfo) return;

    const channel = data.channel || 'general';
    const channelKey = getChannelKey(userInfo.scopeType, userInfo.scopeId, channel);

    if (!typingUsers.has(channelKey)) {
      typingUsers.set(channelKey, new Map());
    }

    const typing = typingUsers.get(channelKey);

    if (data.isTyping) {
      typing.set(data.username, Date.now());
    } else {
      typing.delete(data.username);
    }

    // Clean up stale typing indicators (older than 5 seconds)
    const now = Date.now();
    for (const [username, timestamp] of typing.entries()) {
      if (now - timestamp > 5000) {
        typing.delete(username);
      }
    }

    // Broadcast typing status
    const roomName = getRoomName(userInfo.scopeType, userInfo.scopeId);
    io.to(roomName).emit('typingUpdate', {
      channel,
      typingUsers: Array.from(typing.keys()).map(username => ({ username, timestamp: typing.get(username) }))
    });
  });

  // Handle reactions
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

      // Broadcast reaction update
      const roomName = getRoomName(userInfo.scopeType, userInfo.scopeId);
      io.to(roomName).emit('reactionAdded', { messageId, emoji, userId: userInfo.userId });
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
        DELETE FROM chat_reactions
        WHERE message_id = $1 AND user_id = $2 AND emoji = $3
      `, [messageId, userInfo.userId, emoji]);

      // Broadcast reaction update
      const roomName = getRoomName(userInfo.scopeType, userInfo.scopeId);
      io.to(roomName).emit('reactionRemoved', { messageId, emoji, userId: userInfo.userId });
    } catch (error) {
      console.error('Error removing reaction:', error.message);
    }
  });

  // Handle disconnect
  socket.on('disconnect', () => {
    const userInfo = connectedUsers.get(socket.id);
    if (userInfo) {
      console.log(`User ${userInfo.userName} disconnected`);

      // Remove from typing indicators
      for (const [channelKey, typing] of typingUsers.entries()) {
        if (typing.has(userInfo.userName)) {
          typing.delete(userInfo.userName);

          // Parse channel key and broadcast update
          const [scopeType, scopeId, channel] = channelKey.split(':');
          const roomName = getRoomName(scopeType, scopeId);
          io.to(roomName).emit('typingUpdate', {
            channel,
            typingUsers: Array.from(typing.keys()).map(username => ({ username, timestamp: typing.get(username) }))
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
