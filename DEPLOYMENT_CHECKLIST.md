# Pre-Deployment Checklist

## Code Quality

- [ ] All PHP files pass linting: `find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;`
- [ ] No debug statements left in code
- [ ] No hardcoded API keys or secrets
- [ ] All sensitive config in `.env` (not in code)
- [ ] `.gitignore` covers all sensitive files

## Security

- [ ] `.env` is in `.gitignore` and not committed
- [ ] SSH keys are properly restricted (600 permissions)
- [ ] HTTPS/SSL enabled in production
- [ ] API keys rotated for production
- [ ] No public access to storage directories via HTTP
- [ ] CSRF protection enabled where needed
- [ ] Rate limiting configured

## Configuration

- [ ] `.env.example` is up-to-date
- [ ] All required environment variables documented
- [ ] PHP limits configured:
  - `upload_max_filesize = 500M`
  - `post_max_size = 500M`
  - `memory_limit = 512M`
  - `max_execution_time = 3600`
- [ ] Database path writable
- [ ] Storage directories created with correct permissions

## Docker Deployment

- [ ] Docker images build successfully: `docker build -t app .`
- [ ] `docker-compose.yml` is correct
- [ ] Volume mounts properly configured
- [ ] Port mappings correct
- [ ] Environment variables passed correctly
- [ ] Health checks defined

## GitLab CI/CD

- [ ] `.gitlab-ci.yml` is present and valid
- [ ] All CI/CD variables configured:
  - [ ] `CI_REGISTRY_USER`
  - [ ] `CI_REGISTRY_PASSWORD`
  - [ ] `DEPLOY_HOST`
  - [ ] `DEPLOY_USER`
  - [ ] `DEPLOY_PATH`
  - [ ] `DEPLOY_SSH_KEY`
  - [ ] `PRODUCTION_URL`
- [ ] SSH key format is correct (with newlines, not URL-encoded)
- [ ] Deploy user has proper permissions on target server
- [ ] SSH key added to deploy user's authorized_keys
- [ ] Pipeline test stages pass

## Database & Storage

- [ ] Database schema migrations applied
- [ ] Initial database created successfully
- [ ] Storage directories exist with 755 permissions
- [ ] uploads/ and results/ directories writable
- [ ] Database backup strategy defined
- [ ] Database compaction scheduled if needed

## Monitoring & Logs

- [ ] Log file paths configured
- [ ] Log rotation configured (logrotate)
- [ ] Error monitoring setup (Sentry, etc.)
- [ ] Health check endpoint working
- [ ] Monitoring dashboard accessible

## API & Integration

- [ ] Model API URL configured and accessible
- [ ] GEMINI_API_KEY or MODEL_API_URL working
- [ ] API timeouts appropriate for large files
- [ ] Error responses properly formatted

## Performance

- [ ] Nginx caching configured
- [ ] Gzip compression enabled
- [ ] Database indexes present
- [ ] Static assets have far-future expires headers
- [ ] Query optimization reviewed

## Documentation

- [ ] README.md is complete and accurate
- [ ] DEPLOYMENT.md is complete
- [ ] GITLAB_DEPLOY.md reviewed
- [ ] Environment variables documented
- [ ] API documentation up-to-date
- [ ] Troubleshooting guide included

## Final Checks

- [ ] Staging deployment successful
- [ ] Staging tests passed
- [ ] Performance acceptable
- [ ] No errors in logs
- [ ] Ready for production?

## Production Deployment

- [ ] Team notified of deployment time
- [ ] Rollback plan prepared
- [ ] Database backup taken
- [ ] Deployment executed
- [ ] Smoke tests run
- [ ] Monitoring alerts functional
- [ ] Team notified of success

## Post-Deployment

- [ ] Monitor logs for errors
- [ ] Verify all endpoints working
- [ ] Test file upload/download
- [ ] Check API response times
- [ ] Verify worker processing files
- [ ] Monitor resource usage
- [ ] Check database size
