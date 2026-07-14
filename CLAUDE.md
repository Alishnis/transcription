# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Web UI

Modern responsive interface at `public/index.html` with:
- Drag-drop file upload
- Language selection (auto-detect, ru, kz, en, etc.)
- Processing mode selection (full text, by speakers, summary)
- Output format selection (PDF, DOCX, TXT, SRT)
- Real-time status polling with progress updates
- Download links for completed jobs
- Processing history table

## REST API

### POST /api/transcribe
Upload file and start transcription job.

**Fields:**
- `file` — audio/video file (multipart/form-data)
- `language` — auto | ru | kz (optional, default: auto)

**Response (202):** `{ "job_id": 32, "status": "processing", "poll_url": "..." }`

### GET /api/transcribe?job_id=32
Poll job status.

**Response when processing/uploading:** `{ "job_id": 32, "status": "processing", "message": "..." }`

**Response when done (200):**
```json
{
  "job_id": 32,
  "status": "done",
  "text": "full transcription",
  "segments": [{"start":"00:00:05", "end":"00:00:10", "speaker":"Speaker 1", "text":"..."}],
  "downloads": {
    "txt": "/api/download.php?job_id=32&type=txt",
    "json": "/api/download.php?job_id=32&type=json",
    "pdf": "/api/download.php?job_id=32&type=pdf"
  }
}
```

**Response when failed (500):** `{ "job_id": 32, "status": "failed", "error": "..." }`

### GET /api/download.php?job_id=32&type=txt
Download result (txt, json, pdf).

## Running the worker

Transcription jobs run asynchronously via `worker.php`, spawned automatically by the API:

```bash
php worker.php <job_id>
```

Requires `.env` file with:
- `MODEL_API_URL` — internal Model API gateway (default: http://65.21.210.122:8017)
- `GEMINI_API_KEY` — optional Gemini API key (if using direct API instead of gateway)

PHP extensions: `pdo_sqlite`, `curl`.

The `storage/` directory and subdirectories (`uploads/`, `results/`) are created automatically.

## Architecture

No framework, no Composer — plain PHP with cURL. Key classes:

- **`src/Transcriber.php`** — orchestrates pipeline: upload → transcribe → postprocess → save results → diarize
- **`src/ModelApi.php`** — Gemini API client. Handles two-step resumable file upload, then `generateContent` with JSON output. Retries on malformed JSON.
- **`src/Database.php`** — SQLite via PDO. Tables: `jobs` (tracks job lifecycle), `user_states` (reserved for future)
- **`src/PdfGenerator.php`** — generates PDF with formatted transcription, speaker labels, timestamps
- **`src/RecreateApi.php`** — utility for audio chunking and format conversion

### Transcription pipeline

1. Web UI (`public/index.html`) sends file to POST `/api/transcribe`
2. API creates job record, saves file, spawns `php worker.php <job_id>`
3. Worker calls `Transcriber::process()`:
   - Detect language if auto
   - Upload audio to Gemini/ModelAPI
   - Transcribe in 300-second chunks (with 5-second overlap for deduplication)
   - Apply whole-file diarization to fix speaker labels
   - Format output as `.txt`, `.json`, `.pdf`
   - Update job status to `done`
4. Web UI polls `/api/transcribe?job_id=X` until done, then offers download links

### Key constants (`config.php`)

| Constant | Value |
|---|---|
| `GEMINI_MODEL` | `gemini-1.5-flash` |
| `SUPPORTED_AUDIO` | mp3, wav, m4a, aac, ogg, oga, opus |
| `SUPPORTED_VIDEO` | mp4, mov |
| `CHUNK_DURATION` | 300 seconds (5 min per chunk) |
| `CHUNK_OVERLAP` | 5 seconds (for dedup at boundaries) |
| `STORAGE_PATH` | `./storage` |
| `DB_PATH` | `./storage/bot.sqlite` |

### Job status lifecycle

`uploaded` → `processing` → `transcribing` → `postprocessing` → `done` (or `failed`)
