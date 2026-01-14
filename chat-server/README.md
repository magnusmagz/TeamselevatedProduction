# TeamsElevated Chat Server

Real-time messaging server for coaches and administrators.

## Features

- Real-time messaging using Socket.IO
- Club-wide and team-specific chat rooms
- Message persistence in PostgreSQL
- JWT authentication (same tokens as main app)
- Coach/Admin only access
- Typing indicators
- Message reactions

## Local Development

1. Install dependencies:
```bash
cd chat-server
npm install
```

2. Create `.env` file (copy from `.env.example`):
```bash
cp .env.example .env
```

3. Configure environment variables:
   - `JWT_SECRET` - Must match the PHP backend's JWT_SECRET
   - `DATABASE_URL` - Your Neon PostgreSQL connection string
   - `ALLOWED_ORIGINS` - Frontend URLs for CORS

4. Run the database migration:
```sql
-- Run this in your PostgreSQL database
-- See: ../database/migrations/005_chat_schema.sql
```

5. Start the server:
```bash
npm start
```

The server will run on `http://localhost:5001`

## Heroku Deployment

Since the main TeamsElevated backend is PHP and this chat server is Node.js, it needs to be deployed as a separate Heroku app.

### Step 1: Create a new Heroku app

```bash
heroku create teamselevated-chat --remote chat
```

### Step 2: Set environment variables

```bash
# JWT_SECRET must match your main app's JWT_SECRET
heroku config:set JWT_SECRET="your-jwt-secret" --app teamselevated-chat

# DATABASE_URL - use your Neon PostgreSQL connection string
heroku config:set DATABASE_URL="postgresql://..." --app teamselevated-chat

# Allowed origins for CORS
heroku config:set ALLOWED_ORIGINS="https://teamselevated.netlify.app,https://your-frontend-domain.com" --app teamselevated-chat

# Node environment
heroku config:set NODE_ENV="production" --app teamselevated-chat
```

### Step 3: Deploy

From the chat-server directory, push to Heroku:

```bash
cd chat-server
git init  # If not already a git repo
git add .
git commit -m "Initial chat server deployment"
heroku git:remote -a teamselevated-chat
git push heroku main
```

Or deploy from the monorepo root using subtree:

```bash
git subtree push --prefix chat-server heroku main
```

### Step 4: Update Frontend

After deploying, update the frontend environment variable:

```
REACT_APP_CHAT_SOCKET_URL=https://teamselevated-chat.herokuapp.com
```

## API Events

### Client -> Server

| Event | Payload | Description |
|-------|---------|-------------|
| `authenticate` | `{ token, scopeType, scopeId }` | Authenticate and join chat room |
| `sendMessage` | `{ text, channel? }` | Send a message |
| `typing` | `{ channel, username, isTyping }` | Typing indicator |
| `addReaction` | `{ messageId, emoji }` | Add emoji reaction |
| `removeReaction` | `{ messageId, emoji }` | Remove emoji reaction |

### Server -> Client

| Event | Payload | Description |
|-------|---------|-------------|
| `authSuccess` | `{ message, user }` | Authentication successful |
| `authError` | `{ message }` | Authentication failed |
| `messageHistory` | `Message[]` | Chat history on connect |
| `receiveMessage` | `Message` | New message received |
| `typingUpdate` | `{ channel, typingUsers }` | Typing status update |
| `reactionAdded` | `{ messageId, emoji, userId }` | Reaction added |
| `reactionRemoved` | `{ messageId, emoji, userId }` | Reaction removed |

## Database Schema

See `../database/migrations/005_chat_schema.sql` for the complete schema.
