# GitLab Deployment Guide

## Prerequisites

- GitLab instance with CI/CD enabled
- Docker Registry access (GitLab Container Registry)
- SSH access to production/staging servers
- Git repository pushed to GitLab

## Setup Steps

### 1. Prepare GitLab CI/CD

The `.gitlab-ci.yml` file is already configured. It will:
- Build Docker image on every push to `main` or `develop`
- Run PHP linting and syntax checks
- Deploy to staging on every develop push
- Deploy to production manually on main branch

### 2. Configure GitLab CI/CD Variables

Go to **Settings → CI/CD → Variables** and add:

#### For Build Stage:
```
CI_REGISTRY_USER        = your_gitlab_username
CI_REGISTRY_PASSWORD    = your_gitlab_personal_access_token
```

#### For Staging Deployment:
```
DEPLOY_HOST_STAGING         = staging.example.com
DEPLOY_USER_STAGING         = deploy_user
DEPLOY_PATH_STAGING         = /home/deploy/transcription-bot
DEPLOY_SSH_KEY_STAGING      = (private SSH key for staging)
STAGING_URL                 = https://staging.example.com
```

#### For Production Deployment:
```
DEPLOY_HOST             = production.example.com
DEPLOY_USER             = deploy_user
DEPLOY_PATH             = /home/deploy/transcription-bot
DEPLOY_SSH_KEY          = (private SSH key for production)
PRODUCTION_URL          = https://transcription.example.com
```

### 3. Server Setup (Staging/Production)

#### On the target server:

```bash
# Create deploy user and directory
sudo useradd -m deploy
sudo mkdir -p /home/deploy/transcription-bot
sudo chown deploy:deploy /home/deploy/transcription-bot

# Install Docker and Docker Compose
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker deploy

# Clone repository
sudo -u deploy git clone https://git.example.com/project/transcription-bot.git /home/deploy/transcription-bot
cd /home/deploy/transcription-bot

# Setup .env file
sudo -u deploy cp .env.example .env
# Edit .env with actual values:
# - MODEL_API_URL
# - GEMINI_API_KEY (if using)
```

#### Setup Docker Compose:

```bash
# Login to GitLab Container Registry
docker login registry.gitlab.com

# Create docker-compose.override.yml for production
cat > docker-compose.override.yml <<EOF
version: '3.8'
services:
  nginx:
    ports:
      - "80:80"
      - "443:443"
    environment:
      - VIRTUAL_HOST=transcription.example.com
      - LETSENCRYPT_HOST=transcription.example.com
      - LETSENCRYPT_EMAIL=admin@example.com
EOF

# Start services
docker-compose up -d
```

### 4. Enable SSL (Optional but Recommended)

Using Let's Encrypt with Nginx Proxy:

```bash
docker-compose up -d letsencrypt-nginx-proxy
# Then add the above VIRTUAL_HOST variables to nginx service
```

## Deployment Process

### Automatic Deployments:

1. **Staging (Auto)**
   - Every push to `develop` branch
   - Automatically builds and deploys

2. **Production (Manual)**
   - Push to `main` branch creates a pipeline
   - Click "Deploy" button in GitLab CI/CD to manually deploy

### Manual Deployment (SSH):

```bash
# SSH into server
ssh deploy@production.example.com

# Go to project directory
cd /home/deploy/transcription-bot

# Pull latest code
git pull origin main

# Pull latest Docker images
docker-compose pull

# Restart services
docker-compose up -d

# Verify
docker-compose logs app
```

## Troubleshooting

### CI/CD Pipeline Fails

Check **CI/CD → Pipelines** for error messages. Common issues:

1. **Registry authentication failed**
   - Verify `CI_REGISTRY_PASSWORD` is a Personal Access Token with registry access

2. **SSH deployment fails**
   - Check `DEPLOY_SSH_KEY` is in proper format (with newlines)
   - Verify SSH key permissions on server: `chmod 600 ~/.ssh/id_rsa`
   - Test SSH: `ssh -T git@gitlab.com`

### Application Issues

```bash
# Check logs
docker-compose logs app
docker-compose logs nginx
docker-compose logs -f worker

# Restart services
docker-compose restart

# Rebuild images
docker-compose up -d --build
```

### Database Issues

```bash
# Access container
docker-compose exec app bash

# Check database
sqlite3 storage/bot.sqlite ".tables"

# Clear storage (if needed)
docker-compose exec app rm -rf storage/uploads/* storage/results/*
```

## Monitoring

### View Logs

```bash
# All services
docker-compose logs

# Specific service
docker-compose logs nginx
docker-compose logs app

# Follow logs in real-time
docker-compose logs -f
```

### Health Checks

```bash
# Test API
curl http://localhost/api/transcribe?job_id=1

# Test web UI
curl http://localhost/ | head -20
```

## Rollback

If deployment fails:

```bash
# Restore previous version
cd /home/deploy/transcription-bot
git checkout HEAD~1
docker-compose pull
docker-compose up -d
```

## Security Notes

- Keep `.env` file with API keys out of version control (already in .gitignore)
- Use GitLab CI/CD variables for sensitive data
- Enable HTTPS in production
- Restrict SSH access by IP
- Regularly update Docker images: `docker-compose pull && docker-compose up -d`

## Performance Optimization

For high-traffic deployments:

1. **Scale workers:**
   ```yaml
   docker-compose up -d --scale worker=3
   ```

2. **Enable caching:**
   Update nginx.conf with appropriate cache headers

3. **Database optimization:**
   ```bash
   docker-compose exec app sqlite3 storage/bot.sqlite "ANALYZE;"
   ```

4. **Monitor resource usage:**
   ```bash
   docker stats
   docker-compose top
   ```
