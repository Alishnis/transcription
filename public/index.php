<?php
// Simple router for built-in PHP server
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files as-is
if (in_array(pathinfo($uri, PATHINFO_EXTENSION), ['js', 'css', 'png', 'jpg', 'gif', 'svg', 'woff', 'woff2', 'ttf'])) {
    return false;
}

// Route /api/transcribe to /api/transcribe.php
if ($uri === '/api/transcribe') {
    $_GET['job_id'] = $_GET['job_id'] ?? null;
    $_POST = $_POST ?? [];
    require __DIR__ . '/api/transcribe.php';
    exit;
}

// Route /api/download to /api/download.php
if (strpos($uri, '/api/download') === 0) {
    require __DIR__ . '/api/download.php';
    exit;
}

// Route /api/history to /api/history.php
if ($uri === '/api/history') {
    require __DIR__ . '/api/history.php';
    exit;
}

// Route /api/status to /api/status.php
if ($uri === '/api/status') {
    require __DIR__ . '/api/status.php';
    exit;
}

// Route /api/upload to /api/upload.php
if ($uri === '/api/upload') {
    require __DIR__ . '/api/upload.php';
    exit;
}

// Root path or .html files → serve ui.html
if ($uri === '/' || pathinfo($uri, PATHINFO_EXTENSION) === 'html') {
    require __DIR__ . '/ui.html';
    exit;
}

// Otherwise 404
http_response_code(404);
echo json_encode(['error' => 'Not found']);
