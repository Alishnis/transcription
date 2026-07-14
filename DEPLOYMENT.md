# Deployment & Setup Guide

## Quick Start (Development)

### Using Apache

1. **Enable mod_rewrite:**
   ```bash
   sudo a2enmod rewrite
   ```

2. **Point VirtualHost to `public/` directory:**
   ```apache
   <VirtualHost *:80>
       DocumentRoot /path/to/transcription-bot/public
       <Directory /path/to/transcription-bot/public>
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

3. **Restart Apache:**
   ```bash
   sudo systemctl restart apache2
   ```

4. The `.htaccess` in `public/` handles routing automatically.

### Using Nginx

1. **Create location block:**
   ```nginx
   server {
       listen 80;
       server_name transcription.local;
       root /path/to/transcription-bot/public;
       
       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }
       
       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }
   }
   ```

2. **Restart Nginx:**
   ```bash
   sudo systemctl restart nginx
   ```

## Environment Setup

1. **Copy `.env` from `.env.example`:**
   ```bash
   cp .env.example .env
   ```

2. **Set API URL** (default uses internal gateway):
   ```bash
   MODEL_API_URL=http://65.21.210.122:8017
   # or set GEMINI_API_KEY for direct Google Gemini API access
   ```

3. **Create storage directories** (created automatically on first run):
   ```bash
   mkdir -p storage/uploads storage/results
   chmod 755 storage
   ```

## Directory Structure

```
transcription-bot/
├── public/
│   ├── index.php          # Router entry point
│   ├── ui.html            # Web interface
│   ├── api/
│   │   ├── transcribe.php  # Upload & status polling
│   │   ├── download.php    # File download
│   │   └── ...
│   └── .htaccess          # Apache routing
├── src/
│   ├── Transcriber.php
│   ├── ModelApi.php
│   ├── Database.php
│   └── ...
├── storage/               # Created at runtime
│   ├── uploads/          # Original audio/video files
│   ├── results/          # Transcribed .txt/.json/.pdf
│   └── bot.sqlite        # Job database
└── worker.php            # Background processor
```

## PHP Requirements

- PHP 8.0+
- Extensions: `pdo_sqlite`, `curl`, `json`, `mbstring`

Verify:
```bash
php -m | grep -E "pdo_sqlite|curl|json|mbstring"
```

## Testing

1. **Start local server** (development only, requires Apache/Nginx for production):
   ```bash
   # With Apache (VirtualHost pointing to public/)
   http://transcription.local/
   
   # Or use production setup above
   ```

2. **Upload a file:**
   - Click on upload zone or drag file
   - Select language and output format
   - Click "Начать обработку" (Start processing)

3. **Monitor progress:**
   - Web UI polls `/api/transcribe?job_id=X` every 500ms
   - Status updates in history table
   - Download links appear when done

## Troubleshooting

### "Ошибка загрузки: Unexpected token '<'"
- **Cause:** Server returning HTML instead of JSON (routing not working)
- **Fix:** Ensure Apache `mod_rewrite` is enabled, or use Nginx config above

### "Job not found" / "Database error"
- **Cause:** `storage/bot.sqlite` not created
- **Fix:** Ensure `storage/` directory is writable:
  ```bash
  chmod 777 storage/
  ```

### Worker not processing files
- **Cause:** Background process didn't spawn or exited
- **Check logs:**
  ```bash
  # PHP errors
  tail -f /var/log/apache2/error.log
  
  # Check if worker running
  ps aux | grep worker.php
  ```

### Quota errors (429 responses)
- **Cause:** Model API gateway rate limit exceeded
- **Fix:** Wait 30+ seconds before retrying; consider batch processing
