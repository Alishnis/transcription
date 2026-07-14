#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/TelegramApi.php';
require_once __DIR__ . '/src/ModelApi.php';
require_once __DIR__ . '/src/MangisozApi.php';
require_once __DIR__ . '/src/RecreateApi.php';
require_once __DIR__ . '/src/PdfGenerator.php';
require_once __DIR__ . '/src/Transcriber.php';

// Ensure storage directories exist
foreach ([STORAGE_PATH, STORAGE_PATH . '/uploads', STORAGE_PATH . '/results'] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

if (!BOT_TOKEN || !MODEL_API_URL) {
    fwrite(STDERR, "ERROR: BOT_TOKEN and MODEL_API_URL must be set in .env\n");
    exit(1);
}

$db          = new Database(DB_PATH);
$tg          = new TelegramApi(BOT_TOKEN);
$model       = new ModelApi(MODEL_API_URL);
$mangisoz    = new MangisozApi(MANGISOZ_API_KEY);
$recreate    = new RecreateApi();
$transcriber = new Transcriber($tg, $model, $db, $mangisoz, $recreate);

echo "Bot running. Press Ctrl+C to stop.\n";

$offset = 0;

while (true) {
    $updates = $tg->getUpdates($offset);

    if (!($updates['ok'] ?? false)) {
        sleep(3);
        continue;
    }

    foreach ($updates['result'] ?? [] as $update) {
        $offset = $update['update_id'] + 1;
        try {
            handleUpdate($update, $tg, $transcriber, $db);
        } catch (Throwable $e) {
            error_log('Update handler error: ' . $e->getMessage());
        }
    }
}

// =============================================================================

function handleUpdate(array $update, TelegramApi $tg, Transcriber $transcriber, Database $db): void
{
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query'], $tg, $transcriber, $db);
        return;
    }

    $msg = $update['message'] ?? null;
    if (!$msg) return;

    $chatId = (int)$msg['chat']['id'];
    $userId = (int)$msg['from']['id'];
    $text   = $msg['text'] ?? '';

    if ($text === '/start') {
        $tg->sendMessage($chatId, implode("\n", [
            '👋 <b>Привет!</b>',
            '',
            'Я делаю транскрибацию аудио и видео с определением спикеров.',
            '',
            '<b>Поддерживаемые форматы:</b>',
            '🎵 Аудио: mp3, wav, m4a, aac, ogg',
            '🎬 Видео: mp4, mov',
            '🎤 Голосовые сообщения',
            '',
            '📎 <b>Просто отправь файл!</b>',
        ]));
        return;
    }

    // Try to extract file from message
    $fileInfo = extractFile($msg);

    if (!$fileInfo) {
        if ($text && !str_starts_with($text, '/')) {
            $tg->sendMessage($chatId, '📎 Отправь аудио или видео файл для транскрибации.');
        }
        return;
    }

    $ext = strtolower($fileInfo['ext']);
    $allSupported = array_merge(SUPPORTED_AUDIO, SUPPORTED_VIDEO);

    if (!in_array($ext, $allSupported, true)) {
        $tg->sendMessage($chatId,
            "❌ Формат <b>.{$ext}</b> не поддерживается.\n\n" .
            "<b>Аудио:</b> mp3, wav, m4a, aac, ogg\n" .
            "<b>Видео:</b> mp4, mov"
        );
        return;
    }

    // Download file from Telegram
    $fileObj = $tg->getFile($fileInfo['file_id']);
    if (!$fileObj || isset($fileObj['_error'])) {
        $reason = $fileObj['_error'] ?? '';
        $hint   = stripos($reason, 'file is too big') !== false
            ? "\n\nФайл превышает лимит Telegram (20 МБ). Попробуйте сжать файл перед отправкой."
            : ($reason ? "\n\n<i>{$reason}</i>" : '');
        $tg->sendMessage($chatId, "❌ Не удалось получить файл от Telegram.{$hint}");
        return;
    }

    $fileName = 'job_' . time() . '_' . $userId . '.' . $ext;
    $destPath = STORAGE_PATH . '/uploads/' . $fileName;

    if (!$tg->downloadFile($fileObj['file_path'], $destPath)) {
        $tg->sendMessage($chatId, '❌ Не удалось загрузить файл. Попробуйте ещё раз.');
        return;
    }

    $duration = (int)(
        $msg['audio']['duration'] ??
        $msg['video']['duration'] ??
        $msg['voice']['duration'] ??
        0
    );

    // Create job in DB
    $jobId = $db->createJob($chatId, $userId, $destPath, $fileObj['file_path'], $duration);
    $db->setUserState($userId, 'awaiting_language', $jobId);

    // Ask for language with inline buttons
    $tg->sendMessage($chatId, '✅ Файл получен! Выберите язык аудио:', [
        'reply_markup' => json_encode([
            'inline_keyboard' => [[
                ['text' => '🇷🇺 Русский',  'callback_data' => "lang:{$jobId}:ru"],
                ['text' => '🇰🇿 Казахский', 'callback_data' => "lang:{$jobId}:kz"],
                ['text' => '🌐 Авто',       'callback_data' => "lang:{$jobId}:auto"],
            ]],
        ]),
    ]);
}

function handleCallback(array $cq, TelegramApi $tg, Transcriber $transcriber, Database $db): void
{
    $data   = $cq['data']                      ?? '';
    $chatId = (int)($cq['message']['chat']['id'] ?? 0);
    $userId = (int)($cq['from']['id']            ?? 0);
    $msgId  = (int)($cq['message']['message_id'] ?? 0);

    if (!str_starts_with($data, 'lang:')) {
        $tg->answerCallbackQuery($cq['id']);
        return;
    }

    [, $jobIdStr, $language] = explode(':', $data, 3);
    $jobId = (int)$jobIdStr;

    $job = $db->getJob($jobId);
    if (!$job || (int)$job['user_id'] !== $userId) {
        $tg->answerCallbackQuery($cq['id'], '⚠️ Задача не найдена.');
        return;
    }

    $tg->answerCallbackQuery($cq['id'], 'Запускаем транскрибацию...');

    $db->updateJob($jobId, ['language' => $language]);
    $db->setUserState($userId, 'idle');

    // Turn the language-selection message into a status message
    $tg->editMessageText($chatId, $msgId, '⏳ Начинаем обработку...');

    // Process synchronously (MVP: no queue)
    $transcriber->process($jobId, $msgId);
}

/**
 * Extract file_id and extension from any Telegram message type that carries a file.
 */
function extractFile(array $msg): ?array
{
    // Voice message
    if (isset($msg['voice'])) {
        return ['file_id' => $msg['voice']['file_id'], 'ext' => 'ogg'];
    }

    // Audio file
    if (isset($msg['audio'])) {
        $name = $msg['audio']['file_name'] ?? 'audio.mp3';
        return ['file_id' => $msg['audio']['file_id'], 'ext' => pathinfo($name, PATHINFO_EXTENSION) ?: 'mp3'];
    }

    // Video file
    if (isset($msg['video'])) {
        $name = $msg['video']['file_name'] ?? 'video.mp4';
        return ['file_id' => $msg['video']['file_id'], 'ext' => pathinfo($name, PATHINFO_EXTENSION) ?: 'mp4'];
    }

    // Document (compressed or uncompressed file)
    if (isset($msg['document'])) {
        $name = $msg['document']['file_name'] ?? '';
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return ['file_id' => $msg['document']['file_id'], 'ext' => $ext ?: 'unknown'];
    }

    return null;
}
