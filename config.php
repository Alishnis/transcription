<?php
declare(strict_types=1);

// Increase PHP limits for large file uploads
@ini_set('upload_max_filesize', '500M');
@ini_set('post_max_size', '500M');
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '3600');

// Load .env if exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!$line || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $_ENV[trim($k)] = trim($v);
    }
}

define('BOT_TOKEN',        $_ENV['BOT_TOKEN']        ?? '');
define('GEMINI_API_KEY',   $_ENV['GEMINI_API_KEY']   ?? '');
define('MANGISOZ_API_KEY', $_ENV['MANGISOZ_API_KEY'] ?? '');
define('MODEL_API_URL',    $_ENV['MODEL_API_URL']    ?? 'http://65.21.210.122:8017');
define('STORAGE_PATH',    __DIR__ . '/storage');
define('DB_PATH',         STORAGE_PATH . '/bot.sqlite');
define('GEMINI_MODEL',    'gemini-1.5-flash');

define('SUPPORTED_AUDIO', ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'oga', 'opus']);
define('SUPPORTED_VIDEO', ['mp4', 'mov']);

define('CHUNK_DURATION',   300); // 5 minutes per chunk (Gemini)
define('CHUNK_DURATION_KZ', 20); // chunk + overlap must stay < 28 s (Mangisoz internal limit)
define('CHUNK_OVERLAP',      5); // 5 seconds overlap between chunks
