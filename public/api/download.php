<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';

function sendError(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

$jobId = (int)($_GET['job_id'] ?? 0);
$type  = $_GET['type'] ?? '';

if (!$jobId) {
    sendError(400, 'Missing or invalid job_id parameter');
}
if (!in_array($type, ['txt', 'json', 'pdf'], true)) {
    sendError(400, 'Invalid type parameter. Use txt, json or pdf');
}

try {
    $db  = new Database(DB_PATH);
    $job = $db->getJob($jobId);

    if ($job === null) {
        sendError(404, 'Job not found');
    }

    if ($job['status'] !== 'done') {
        sendError(409, 'Job is not finished yet (status: ' . $job['status'] . ')');
    }

    $pathKey = match($type) {
        'json'  => 'result_json_path',
        'pdf'   => 'result_pdf_path',
        default => 'result_txt_path',
    };
    $filePath = $job[$pathKey] ?? '';

    if ($filePath === '' || !file_exists($filePath)) {
        sendError(404, 'Result file not found');
    }

    $basename    = 'transcription_job' . $jobId . '.' . $type;
    $contentType = match($type) {
        'json'  => 'application/json',
        'pdf'   => 'application/pdf',
        default => 'text/plain; charset=utf-8',
    };

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $basename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache');

    readfile($filePath);
} catch (Throwable $e) {
    error_log('[download.php] ' . $e->getMessage());
    sendError(500, 'Internal server error');
}
