# Deployment Guide

## Git Remotes

This project has multiple deployment targets:

| Remote | URL | Purpose |
|--------|-----|---------|
| `origin` | github.com/magnusmagz/TeamselevatedProduction | GitHub repo **AND** triggers Netlify frontend deploy |
| `heroku` | git.heroku.com/teamselevated-backend | **PHP Backend API** (manual push required) |
| `heroku-frontend` | git.heroku.com/teamselevated-frontend | Legacy/unused — frontend is on Netlify |
| `chat` | git.heroku.com/teamselevated-chat | Chat server (Node) — requires subtree split, see below |

**Key rule:** `git push origin main` ships the frontend (via Netlify) but does **NOT** ship the backend. `git push heroku main` must be run separately for any PHP / backend / database / worker change to actually go live.

## Deployment Commands

### Deploy Backend (PHP API)
```bash
git push heroku main
```
This deploys to: https://teamselevated-backend-0485388bd66e.herokuapp.com/

### Deploy Frontend (React + Netlify)
```bash
git push origin main
```
Netlify watches `origin/main` and auto-builds + deploys the React PWA. This is also your GitHub backup push — same command covers both.

### Deploy Everything (full-stack change)
```bash
git push origin main && git push heroku main
```

### Deploy Chat Server (Node)
The chat server lives in the `chat-server/` subdirectory but its Heroku app expects a Node Procfile at the root. Use a subtree split so Heroku only sees `chat-server/`:
```bash
git push chat $(git subtree split --prefix chat-server main):main --force
```
The `--force` is required because subtree split creates a new history each time.

## Common Workflow

1. Make changes locally
2. Test on localhost
3. Commit changes:
   ```bash
   git add <files>
   git commit -m "Description of changes"
   ```
4. Push to GitHub AND Heroku:
   ```bash
   git push origin main
   git push heroku main
   ```

## Worker Dyno — Must Stay Scaled to 1

The backend has a `worker` dyno declared in the Procfile that processes
the Redis job queue (email, SMS, imports, broadcasts). **It must be
scaled to at least 1 or the entire comms + importer subsystem silently
breaks** — jobs queue up forever and nothing errors visibly.

### Check worker is running
```bash
heroku ps -a teamselevated-backend
```
You should see a `worker.1: up` line alongside `web.1: up`. If you only
see `web.1`, the worker is off and every queued email/SMS/import is
silently stuck in Redis.

### Scale the worker back up
```bash
heroku ps:scale worker=1 -a teamselevated-backend
```

### Tail worker logs
```bash
heroku logs -a teamselevated-backend --dyno worker -n 100
```

### Cost
~$7/month flat on the Basic plan. 24/7, no sleep. This is not optional
if you want email/SMS/imports to actually work.

## Verify Deployment

After pushing to Heroku, verify the deployment:
```bash
# Check backend is live (release number should have incremented)
heroku releases -a teamselevated-backend | head -3

# Check worker dyno is up (critical — see section above)
heroku ps -a teamselevated-backend

# Smoke-test an endpoint (401 = auth required but route exists, 404 = route missing)
curl -I "https://teamselevated-backend-0485388bd66e.herokuapp.com/api/imports-gateway.php?action=status&job_id=1"
```

## Troubleshooting

### Backend changes not appearing after `git push origin main`?
**You also need to push to Heroku!**
```bash
git push heroku main
```
GitHub (`origin`) only updates the repo and triggers the Netlify frontend build. Backend PHP changes don't go live until you push to the `heroku` remote.

### Frontend changes not appearing after `git push origin main`?
Check the Netlify build status at the Netlify dashboard. Typical build time is 1-3 minutes. A hard browser reload (Cmd+Shift+R) may be needed to defeat service-worker caching on the PWA.

### Email/SMS/imports appear to "send" but nothing happens?
Almost always means the worker dyno is scaled to 0. See the **Worker Dyno** section above — run `heroku ps -a teamselevated-backend` and look for a `worker.1: up` line.

### Neon DB schema changes
Migrations live in `database/migrations/` as sequential SQL files. Apply directly with psql:
```bash
PGPASSWORD="$(heroku config:get DB_PASSWORD -a teamselevated-backend)" \
  psql "host=$(heroku config:get DB_HOST -a teamselevated-backend) dbname=$(heroku config:get DB_NAME -a teamselevated-backend) user=$(heroku config:get DB_USER -a teamselevated-backend) sslmode=require" \
  -f database/migrations/0XX_your_migration.sql
```
Neon is not a Heroku-managed addon — it's a direct connection via `DB_*` config vars.

### View Heroku logs
```bash
heroku logs --tail --app teamselevated-backend
# Or worker-specific:
heroku logs --app teamselevated-backend --dyno worker -n 100
```

### Check Heroku status
```bash
heroku ps --app teamselevated-backend
```

### Recent releases
```bash
heroku releases --app teamselevated-backend | head -10
```
