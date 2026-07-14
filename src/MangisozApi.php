<?php
declare(strict_types=1);

class MangisozApi
{
    private const BASE = 'https://mangisoz.nu.edu.kz/backend';

    public function __construct(private string $apiKey) {}

    /**
     * Transcribe a Kazakh audio file. Returns segments in the same shape as GeminiApi::transcribe().
     */
    public function transcribe(string $filePath, string $mimeType, string $language = 'kz'): array
    {
        $langCode = match ($language) {
            'ru'    => 'ru',
            default => 'kk',  // kz and anything else → Kazakh
        };

        $ch = curl_init(self::BASE . '/api/v1/stt/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->apiKey],
            CURLOPT_POSTFIELDS     => [
                'file'             => new CURLFile($filePath, $mimeType),
                'language'         => $langCode,
                'response_format'  => 'verbose_json',
            ],
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new RuntimeException("Mangisoz API error HTTP {$httpCode}: {$raw}");
        }

        $data = json_decode((string)$raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Mangisoz returned invalid JSON');
        }

        $sample = ($data['segments'][0] ?? $data['asr']['segments'][0] ?? $data);
        error_log('[Mangisoz sample segment] ' . json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $this->normalize($data);
    }

    /**
     * Translate text from $from language to $to language.
     * Max input: 12 000 characters.
     */
    public function translate(string $text, string $from = 'kk', string $to = 'ru'): string
    {
        $ch = curl_init(self::BASE . '/api/v1/translate/text');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'text'            => $text,
                'source_language' => $from,
                'target_language' => $to,
            ]),
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new RuntimeException("Mangisoz translate HTTP {$httpCode}: {$raw}");
        }

        $data = json_decode((string)$raw, true);
        return $data['translated_text'] ?? $text;
    }

    private function normalize(array $data): array
    {
        // OpenAI-style endpoint returns segments at top level; regular endpoint nests under asr
        $rawSegments = $data['segments'] ?? $data['asr']['segments'] ?? [];

        if (empty($rawSegments)) {
            return [[
                'start'   => '00:00:00.000',
                'end'     => '00:00:00.000',
                'speaker' => 'Speaker 1',
                'text'    => $data['text'] ?? '',
            ]];
        }

        return array_map(fn(array $seg) => [
            'start'   => $this->secsToTs((float)($seg['start'] ?? 0)),
            'end'     => $this->secsToTs((float)($seg['end']   ?? 0)),
            'speaker' => 'Speaker 1',
            'text'    => trim($seg['text'] ?? ''),
        ], $rawSegments);
    }

    private function secsToTs(float $s): string
    {
        $h  = (int)($s / 3600);
        $m  = (int)(fmod($s, 3600) / 60);
        $sc = (int)fmod($s, 60);
        $ms = (int)round(fmod($s, 1) * 1000);
        return sprintf('%02d:%02d:%02d.%03d', $h, $m, $sc, $ms);
    }
}
