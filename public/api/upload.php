<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/src/Database.php';

function jsonError(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'Method not allowed');
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой',
        UPLOAD_ERR_NO_FILE                        => 'Файл не выбран',
        default                                   => 'Ошибка загрузки файла (код ' . $uploadError . ')',
    };
    jsonError(400, $msg);
}

$originalName = $_FILES['file']['name'];
$ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowed      = array_merge(SUPPORTED_AUDIO, SUPPORTED_VIDEO);

if (!in_array($ext, $allowed, true)) {
    jsonError(400, 'Неподдерживаемый формат файла. Разрешены: ' . implode(', ', $allowed));
}

$language = $_POST['language'] ?? 'auto';
if (!in_array($language, ['ru', 'kz', 'en', 'auto'], true)) {
    $language = 'auto';
}

$uploadsDir = STORAGE_PATH . '/uploads';
if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0755, true)) {
    jsonError(500, 'Не удалось создать директорию для загрузок');
}

$filename = 'web_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadsDir . '/' . $filename;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
    jsonError(500, 'Не удалось сохранить файл');
}

try {
    $db    = new Database(DB_PATH);
    $jobId = $db->createJob(0, 0, $destPath, '', 0);
    $db->updateJob($jobId, ['language' => $language, 'status_message' => 'Файл загружен']);

    $workerScript = dirname(__DIR__, 2) . '/worker.php';
    $cmd = 'php ' . escapeshellarg($workerScript) . ' ' . $jobId . ' > /dev/null 2>&1 &';
    exec($cmd);

    echo json_encode(['job_id' => $jobId, 'status' => 'processing']);
} catch (Throwable $e) {
    @unlink($destPath);
    error_log('[upload.php] ' . $e->getMessage());
    jsonError(500, 'Внутренняя ошибка сервера');
}
