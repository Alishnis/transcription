# Transcription Bot 🎙️

Modern web interface for audio/video transcription with speaker diarization and multiple output formats.

## Quick Start

### 1. Setup Environment
```bash
cp .env.example .env
# Edit .env and set MODEL_API_URL or GEMINI_API_KEY
```

### 2. Run Development Server
```bash
# Default port 8000
./start-dev.sh

# Custom port
./start-dev.sh 9000
```

### 3. Open in Browser
```
http://localhost:8000/
```

## Usage

1. **Upload** — drag-drop audio/video file
2. **Select language** — auto-detect or manual (Russian, Kazakh, English, etc.)
3. **Choose output** — full text, by speakers, or summary
4. **Pick format** — PDF, DOCX, TXT, or SRT
5. **Process** — click "Начать обработку" and wait for results
6. **Download** — get transcription when done

## Features

✅ Real-time status polling  
✅ Speaker diarization (М/Ж gender labels)  
✅ Multiple output formats (PDF, DOCX, TXT, SRT)  
✅ Chunked transcription (300s chunks with dedup)  
✅ Whole-file speaker correction  
✅ Mobile responsive UI  
✅ Processing history  

## File Structure

```
public/
├── ui.html          # Web interface
├── index.php        # Router entry point
└── api/
    ├── transcribe.php   # Upload & polling
    ├── download.php     # File download
    └── ...

src/
├── Transcriber.php    # Main pipeline
├── ModelApi.php       # Gemini integration
├── Database.php       # SQLite jobs
└── ...

worker.php            # Background processor
```

## API

### POST /api/transcribe
Upload file for transcription.
```bash
curl -X POST http://localhost:8000/api/transcribe \
  -F "file=@audio.mp3" \
  -F "language=auto"
```

**Response (202):**
```json
{
  "job_id": 32,
  "status": "processing",
  "poll_url": "http://localhost:8000/api/transcribe?job_id=32"
}
```

### GET /api/transcribe?job_id=32
Poll job status.

**Response when done:**
```json
{
  "job_id": 32,
  "status": "done",
  "text": "full transcription...",
  "segments": [...],
  "downloads": {
    "txt": "http://localhost:8000/api/download.php?job_id=32&type=txt",
    "json": "...",
    "pdf": "..."
  }
}
```

### GET /api/download.php?job_id=32&type=txt
Download result file (txt, json, pdf).

## Requirements

- PHP 8.0+
- Extensions: `pdo_sqlite`, `curl`, `json`, `mbstring`
- Model API URL or Gemini API key

## Production Deployment

### With Docker (Recommended)

```bash
# Using Docker Compose
docker-compose up -d

# Access at http://localhost
```

See [DEPLOYMENT.md](DEPLOYMENT.md) for Apache/Nginx manual setup.

### GitLab CI/CD Deployment

See [GITLAB_DEPLOY.md](GITLAB_DEPLOY.md) for complete GitLab integration, CI/CD pipeline, and automated deployments to staging/production.

### Development with Docker

```bash
docker-compose -f docker-compose.dev.yml up
# Access at http://localhost:8000
```

## Troubleshooting

**Q: "Ошибка загрузки: Unexpected token '<'"**  
A: Server routing issue. Ensure development server is running or Apache/Nginx is configured correctly.

**Q: "Job not found"**  
A: Database not initialized. Check `storage/` directory is writable.

**Q: Worker not processing**  
A: Background process failed. Check PHP error logs.

## License

Proprietary
