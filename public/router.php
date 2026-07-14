<?php
// Router for PHP built-in server and basic web servers
// Maps /api/transcribe to /api/transcribe.php, etc.

$requested = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requested = str_replace('/api/', '', $requested);

$map = [
    'transcribe' => '/api/transcribe.php',
    'download'   => '/api/download.php',
    'status'     => '/api/status.php',
    'upload'     => '/api/upload.php',
    'history'    => '/api/history.php',
];

// Check if it's an API request
if (preg_match('/^\/api\/(\w+)/', $_SERVER['REQUEST_URI'], $m)) {
    $endpoint = $m[1];
    $file = $map[$endpoint] ?? null;
    if ($file && file_exists(__DIR__ . $file)) {
        require __DIR__ . $file;
        exit;
    }
}

// Serve static files
if (file_exists(__DIR__ . $_SERVER['REQUEST_URI'])) {
    return false; // Let PHP server serve the file
}

// Default to index.html for root or non-API routes
if ($_SERVER['REQUEST_URI'] === '/' || !str_contains($_SERVER['REQUEST_URI'], '.')) {
    require __DIR__ . '/index.html';
    exit;
}

http_response_code(404);
echo '{"error": "Not found"}';
