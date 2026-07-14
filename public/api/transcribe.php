<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/Database.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db     = new Database(DB_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -------------------------------------------------------------------------
// DELETE /api/transcribe?job_id=32 (cancel job)
// -------------------------------------------------------------------------
if ($method === 'DELETE') {
    $jobId = (int)($_GET['job_id'] ?? 0);
    if (!$jobId) {
        jsonOut(['error' => 'job_id is required'], 400);
    }

    $job = $db->getJob($jobId);
    if (!$job) {
        jsonOut(['error' => 'Job not found'], 404);
    }

    if (in_array($job['status'], ['done', 'failed'], true)) {
        jsonOut(['error' => 'Cannot cancel completed job'], 409);
    }

    $db->requestCancel($jobId);
    jsonOut(['job_id' => $jobId, 'status' => 'cancel_requested']);
}

// -------------------------------------------------------------------------
// GET /api/transcribe?job_id=32
// -------------------------------------------------------------------------
if ($method === 'GET') {
    $jobId = (int)($_GET['job_id'] ?? 0);
    if (!$jobId) {
        jsonOut(['error' => 'job_id is required'], 400);
    }

    $job = $db->getJob($jobId);
    if (!$job) {
        jsonOut(['error' => 'Job not found'], 404);
    }

    $status = $job['status'];
    $out    = [
        'job_id'   => $jobId,
        'status'   => $status,
        'message'  => $job['status_message'] ?? null,
        'progress' => (int)($job['progress'] ?? 0),
    ];

    if ($status === 'failed') {
        $out['error'] = $job['error_message'] ?? 'Unknown error';
        jsonOut($out, 500);
    }

    if ($status === 'done') {
        $out['summary'] = $job['summary'] ?? null;

        // Full text
        $txtPath = $job['result_txt_path'] ?? '';
        $out['text'] = $txtPath && file_exists($txtPath) ? file_get_contents($txtPath) : null;

        // Segments
        $jsonPath = $job['result_json_path'] ?? '';
        if ($jsonPath && file_exists($jsonPath)) {
            $parsed        = json_decode((string)file_get_contents($jsonPath), true);
            $out['segments'] = $parsed['segments'] ?? [];
        }

        // Download URLs
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $out['downloads'] = [
            'txt'  => $base . '/api/download.php?job_id=' . $jobId . '&type=txt',
            'json' => $base . '/api/download.php?job_id=' . $jobId . '&type=json',
        ];

        if (!empty($job['result_pdf_path']) && file_exists($job['result_pdf_path'])) {
            $out['downloads']['pdf'] = $base . '/api/download.php?job_id=' . $jobId . '&type=pdf';
        }
    }

    jsonOut($out);
}

// -------------------------------------------------------------------------
// POST /api/transcribe  (multipart/form-data)
// Fields:
//   file      — audio or video file (required)
//   language  — kz | ru | auto (optional, default: auto)
// -------------------------------------------------------------------------
if ($method === 'POST') {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errMsg = match ($_FILES['file']['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large',
            UPLOAD_ERR_NO_FILE                        => 'No file uploaded',
            default                                   => 'Upload error',
        };
        jsonOut(['error' => $errMsg], 400);
    }

    $language = strtolower(trim($_POST['language'] ?? 'auto'));
    if (!in_array($language, ['kz', 'ru', 'auto'], true)) {
        jsonOut(['error' => 'language must be kz, ru, or auto'], 400);
    }

    $mode = strtolower(trim($_POST['mode'] ?? 'text'));
    if (!in_array($mode, ['text', 'speakers', 'summary'], true)) {
        $mode = 'text'; // default
    }

    $origName = $_FILES['file']['name'];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!in_array($ext, array_merge(SUPPORTED_AUDIO, SUPPORTED_VIDEO), true)) {
        jsonOut(['error' => 'Unsupported file type: ' . $ext], 415);
    }

    foreach ([STORAGE_PATH, STORAGE_PATH . '/uploads', STORAGE_PATH . '/results'] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }

    // Save file to uploads/
    $jobId    = $db->createJob(0, 0, '', '', 0);
    $destPath = STORAGE_PATH . '/uploads/job_' . $jobId . '.' . $ext;
    move_uploaded_file($_FILES['file']['tmp_name'], $destPath);

    $db->updateJob($jobId, [
        'original_file_path' => $destPath,
        'language'           => $language,
        'mode'               => $mode,
        'status'             => 'uploaded',
    ]);

    // Spawn background worker
    $workerCmd = 'php ' . escapeshellarg(__DIR__ . '/../../worker.php') . ' ' . $jobId . ' > /dev/null 2>&1 &';
    exec($workerCmd);

    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    jsonOut([
        'job_id'      => $jobId,
        'status'      => 'processing',
        'poll_url'    => $base . '/api/transcribe?job_id=' . $jobId,
    ], 202);
}

jsonOut(['error' => 'Method not allowed'], 405);
