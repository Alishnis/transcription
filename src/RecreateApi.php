<?php
declare(strict_types=1);

class RecreateApi
{
    private const BASE = 'https://api2.recreate.video';

    /**
     * Extract audio from a local video file.
     * Returns ['url' => string, 'duration' => float] — duration is 0.0 when
     * the service didn't report it (e.g. header-only response).
     */
    public function extractAudio(string $filePath, string $mimeType): array
    {
        $ch = curl_init(self::BASE . '/extractAudioFromVideo');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HEADER         => true,
            CURLOPT_POSTFIELDS     => [
                'video_file'    => new CURLFile($filePath, $mimeType),
                'response_type' => 'json',
            ],
        ]);
        $response   = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $body = substr((string)$response, $headerSize);

        if ($httpCode === 413) {
            throw new RuntimeException('Файл слишком большой для облачной обработки (лимит хостинга ~100 МБ). Попробуйте сжать файл или укоротить запись.');
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("RecreateAPI extractAudio HTTP {$httpCode}: {$body}");
        }

        $data     = json_decode($body, true);
        $duration = is_array($data) ? (float)($data['duration'] ?? 0) : 0.0;

        $headers = substr((string)$response, 0, $headerSize);
        if (preg_match('/X-Audio-Url:\s*(\S+)/i', $headers, $m)) {
            return ['url' => trim($m[1]), 'duration' => $duration];
        }

        if (is_string($data) && str_starts_with($data, 'http')) {
            return ['url' => $data, 'duration' => 0.0];
        }
        if (!empty($data['file_url'])) {
            return ['url' => $data['file_url'], 'duration' => $duration];
        }

        throw new RuntimeException("RecreateAPI extractAudio: no audio URL in response: {$body}");
    }

    /**
     * Upload a file (by URL) to cloud storage and return the stable uploaded_url.
     * Polls until the upload task reaches SUCCESS status.
     */
    public function uploadFileByUrl(string $fileUrl, string $provider = 'yandex'): string
    {
        $url = self::BASE . '/uploadFiles/uploadFileByUrl?' . http_build_query([
            'file_url' => $fileUrl,
            'provider' => $provider,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new RuntimeException("RecreateAPI uploadFileByUrl HTTP {$httpCode}: {$raw}");
        }

        $data   = json_decode((string)$raw, true);
        // Response may be an object {} or array [{}]
        $item   = isset($data[0]) ? $data[0] : $data;
        $taskId = $item['task_id'] ?? null;
        if (!$taskId) {
            throw new RuntimeException("RecreateAPI uploadFileByUrl: no task_id in response: {$raw}");
        }

        return $this->pollUploadTask($taskId);
    }

    private function pollUploadTask(string $taskId): string
    {
        for ($i = 0; $i < 60; $i++) {
            sleep(2);

            $ch = curl_init(self::BASE . '/uploadFiles/taskStatus/' . $taskId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $raw      = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 200) continue;

            $data   = json_decode((string)$raw, true);
            $item   = isset($data[0]) ? $data[0] : $data;
            $status = $item['status'] ?? '';

            if ($status === 'SUCCESS') {
                $url = $item['result']['uploaded_url'] ?? $item['uploaded_url'] ?? null;
                if (!$url) {
                    throw new RuntimeException("RecreateAPI upload SUCCESS but no uploaded_url: {$raw}");
                }
                return $url;
            }

            if (in_array($status, ['FAILURE', 'failed', 'error', 'FAILED'], true)) {
                throw new RuntimeException("RecreateAPI upload task failed: {$raw}");
            }
            // queued / processing — keep polling
        }

        throw new RuntimeException("RecreateAPI upload task timed out (task_id: {$taskId})");
    }

    /**
     * Cut a remote audio file and return the URL of the resulting chunk.
     *
     * @param int $startMs start offset in milliseconds
     * @param int $endMs   end offset in milliseconds
     */
    public function cutAudio(string $audioUrl, int $startMs, int $endMs): string
    {
        $url = self::BASE . '/cutAudio/?' . http_build_query([
            'url'        => $audioUrl,
            'time_start' => $startMs,
            'time_end'   => $endMs,
            'mode'       => 'crop',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HEADER         => true,
        ]);
        $response   = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $body = substr((string)$response, $headerSize);

        if ($httpCode !== 200) {
            throw new RuntimeException("RecreateAPI cutAudio HTTP {$httpCode}: {$body}");
        }

        // Prefer X-Audio-Url header
        $headers = substr((string)$response, 0, $headerSize);
        if (preg_match('/X-Audio-Url:\s*(\S+)/i', $headers, $m)) {
            return trim($m[1]);
        }

        // Fall back to JSON body
        $data = json_decode($body, true);
        if (is_string($data) && str_starts_with($data, 'http')) {
            return $data;
        }
        if (!empty($data['file_url'])) {
            return $data['file_url'];
        }

        throw new RuntimeException("RecreateAPI cutAudio: no file URL in response: {$body}");
    }
}
