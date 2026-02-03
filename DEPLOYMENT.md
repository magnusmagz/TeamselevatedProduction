# Deployment Guide

## Git Remotes

This project has multiple deployment targets:

| Remote | URL | Purpose |
|--------|-----|---------|
| `origin` | github.com/magnusmagz/TeamselevatedProduction | GitHub repo (code backup) |
| `heroku` | git.heroku.com/teamselevated-backend | **PHP Backend API** |
| `heroku-frontend` | git.heroku.com/teamselevated-frontend | Frontend (if hosted on Heroku) |
| `chat` | git.heroku.com/teamselevated-chat | Chat server |

## Deployment Commands

### Deploy Backend (PHP API)
```bash
git push heroku main
```
This deploys to: https://teamselevated-backend-0485388bd66e.herokuapp.com/

### Push to GitHub (backup/CI)
```bash
git push origin main
```

### Deploy Everything
```bash
git push origin main && git push heroku main
```

### Deploy Chat Server
```bash
git push chat main
```

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

## Verify Deployment

After pushing to Heroku, verify the deployment:
```bash
# Check backend API
curl https://teamselevated-backend-0485388bd66e.herokuapp.com/api/health.php

# Check a specific endpoint
curl https://teamselevated-backend-0485388bd66e.herokuapp.com/api/venues.php
```

## Troubleshooting

### Changes not appearing after `git push origin main`?
**You also need to push to Heroku!**
```bash
git push heroku main
```
GitHub (`origin`) is just for code backup. Heroku is the actual deployment.

### View Heroku logs
```bash
heroku logs --tail --app teamselevated-backend
```

### Check Heroku status
```bash
heroku ps --app teamselevated-backend
```
