<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';

$jobId = (int)($_GET['id'] ?? 0);
if (!$jobId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid id parameter']);
    exit;
}

try {
    $db  = new Database(DB_PATH);
    $job = $db->getJob($jobId);

    if ($job === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found']);
        exit;
    }

    $progressMap = [
        'uploaded'       => 10,
        'processing'     => 25,
        'transcribing'   => 60,
        'postprocessing' => 85,
        'done'           => 100,
        'failed'         => 0,
    ];

    $status       = $job['status'];
    $progress     = $progressMap[$status] ?? 0;
    $statusMessage = $job['status_message'] ?? '';

    // Some chunks may have failed and been skipped even though the job
    // completed — surface that as a distinct warning the results card can show
    $warning = null;
    if ($status === 'done' && str_contains($statusMessage, '⚠️')) {
        $warning = trim(str_replace(['✅', 'Готово'], '', $statusMessage), " ()\t");
    }

    echo json_encode([
        'id'             => (int)$job['id'],
        'status'         => $status,
        'status_message' => $statusMessage,
        'progress'       => $progress,
        'has_pdf'        => !empty($job['result_pdf_path']) && file_exists($job['result_pdf_path']),
        'summary'        => $status === 'done' ? ($job['summary'] ?? null) : null,
        'warning'        => $warning,
        'error'          => $job['error_message'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[status.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
