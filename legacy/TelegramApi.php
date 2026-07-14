<?php
declare(strict_types=1);

class TelegramApi
{
    private string $base;

    public function __construct(private string $token)
    {
        $this->base = "https://api.telegram.org/bot{$token}";
    }

    public function getUpdates(int $offset = 0, int $timeout = 30): array
    {
        return $this->call('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function sendMessage(int $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $extra = []): array
    {
        return $this->call('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function answerCallbackQuery(string $id, string $text = ''): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]);
    }

    public function sendDocument(int $chatId, string $filePath, string $caption = ''): array
    {
        $ch = curl_init("{$this->base}/sendDocument");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POSTFIELDS     => [
                'chat_id'  => $chatId,
                'caption'  => $caption,
                'document' => new CURLFile($filePath),
            ],
        ]);
        $res = json_decode(curl_exec($ch), true);
        return $res ?? [];
    }

    /**
     * Returns the file object on success, or ['error' => '...'] on failure.
     */
    public function getFile(string $fileId): ?array
    {
        $res = $this->call('getFile', ['file_id' => $fileId]);
        if ($res['ok'] ?? false) {
            return $res['result'];
        }
        // Surface Telegram's error description for caller to inspect
        return isset($res['description']) ? ['_error' => $res['description']] : null;
    }

    public function downloadFile(string $remotePath, string $destPath): bool
    {
        $url   = "https://api.telegram.org/file/bot{$this->token}/{$remotePath}";
        $bytes = file_get_contents($url);
        if ($bytes === false) return false;
        return file_put_contents($destPath, $bytes) !== false;
    }

    private function call(string $method, array $params = []): array
    {
        $ch = curl_init("{$this->base}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 35,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($params),
        ]);
        $res = curl_exec($ch);
        return json_decode((string)$res, true) ?? [];
    }
}
