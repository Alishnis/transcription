<?php
declare(strict_types=1);

class GeminiApi
{
    private const BASE_UPLOAD  = 'https://generativelanguage.googleapis.com/upload/v1beta/files';
    private const BASE_CONTENT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(private string $apiKey) {}

    /**
     * Upload a local file to Gemini Files API and return its URI.
     */
    public function uploadFile(string $filePath, string $mimeType): string
    {
        $bytes = file_get_contents($filePath);
        if ($bytes === false) {
            throw new RuntimeException("Cannot read file: {$filePath}");
        }
        $size = strlen($bytes);

        // Step 1 — start resumable upload
        $ch = curl_init(self::BASE_UPLOAD . '?uploadType=resumable&key=' . $this->apiKey);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'X-Goog-Upload-Protocol: resumable',
                'X-Goog-Upload-Command: start',
                "X-Goog-Upload-Header-Content-Length: {$size}",
                "X-Goog-Upload-Header-Content-Type: {$mimeType}",
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['file' => ['display_name' => basename($filePath)]]),
        ]);
        $response   = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $headers = substr((string)$response, 0, $headerSize);
        preg_match('/X-Goog-Upload-URL:\s*(\S+)/i', $headers, $m);
        if (empty($m[1])) {
            throw new RuntimeException("Gemini upload init failed (HTTP {$httpCode})");
        }
        $uploadUrl = $m[1];

        // Step 2 — send bytes and finalize
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => [
                'X-Goog-Upload-Command: upload, finalize',
                'X-Goog-Upload-Offset: 0',
                "Content-Length: {$size}",
                "Content-Type: {$mimeType}",
            ],
            CURLOPT_POSTFIELDS => $bytes,
        ]);
        $res = json_decode((string)curl_exec($ch), true);

        if (empty($res['file']['uri'])) {
            throw new RuntimeException('Gemini file upload failed: ' . json_encode($res));
        }
        return $res['file']['uri'];
    }

    /**
     * Transcribe audio via Gemini. Returns array of segment objects.
     */
    public function transcribe(string $fileUri, string $mimeType, string $language = 'auto'): array
    {
        $segments = $this->callGemini($fileUri, $mimeType, $this->buildPrompt($language));

        if ($segments === null) {
            // One retry with a stricter, minimal prompt
            $segments = $this->callGemini($fileUri, $mimeType, $this->buildRetryPrompt($language));
        }

        if ($segments === null) {
            throw new RuntimeException('Gemini returned invalid JSON after retry');
        }
        return $segments;
    }

    private function callGemini(string $fileUri, string $mimeType, string $prompt): ?array
    {
        $url  = sprintf(self::BASE_CONTENT, GEMINI_MODEL) . '?key=' . $this->apiKey;
        $body = [
            'contents' => [[
                'parts' => [
                    ['file_data' => ['mime_type' => $mimeType, 'file_uri' => $fileUri]],
                    ['text'      => $prompt],
                ],
            ]],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature'        => 0,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new RuntimeException("Gemini API error HTTP {$httpCode}: {$raw}");
        }

        $data = json_decode((string)$raw, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip possible markdown wrapper (safety net)
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', $text);

        $parsed = json_decode(trim($text), true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['segments'])) {
            return null;
        }
        return $parsed['segments'];
    }

    /**
     * Add punctuation to Kazakh text using its Russian translation as reference.
     * Segment separator ||| must be preserved in output.
     */
    public function addPunctuation(string $kazakhText, string $russianText): string
    {
        $url    = sprintf(self::BASE_CONTENT, GEMINI_MODEL) . '?key=' . $this->apiKey;
        $prompt = <<<PROMPT
You are a Kazakh punctuation editor.

Kazakh text WITHOUT punctuation (segments separated by |||):
{$kazakhText}

Russian translation (for sentence boundary reference):
{$russianText}

Task: Add correct punctuation (periods, commas, question marks, exclamation marks) to the Kazakh text.
Rules:
- Keep the ||| segment separators exactly as they appear — do not add or remove any.
- Preserve the original Kazakh words exactly — do not translate or paraphrase.
- Use sentence boundaries from the Russian translation as a guide.
- Return ONLY the punctuated Kazakh text with ||| separators. No explanations.
PROMPT;

        $body = [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new RuntimeException("Gemini punctuation error HTTP {$httpCode}: {$raw}");
        }

        $data = json_decode((string)$raw, true);
        return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? $kazakhText);
    }

    private function buildPrompt(string $language): string
    {
        $langHint = match ($language) {
            'ru'    => 'The audio is in Russian.',
            'kz'    => 'The audio is in Kazakh. Do NOT translate to Russian or any other language.',
            default => 'Detect the language automatically. Expected: Russian or Kazakh.',
        };

        return <<<PROMPT
You are a professional transcription engine.

{$langHint}

Requirements:
- Preserve the original language exactly. Do not translate.
- Add correct punctuation and fix obvious spelling mistakes.
- Detect speaker changes (use "Speaker 1", "Speaker 2", etc.).
- Use timestamps in HH:MM:SS.mmm format.
- Return ONLY valid JSON. No markdown. No extra text.

Output format:
{
  "segments": [
    {
      "start": "HH:MM:SS.mmm",
      "end": "HH:MM:SS.mmm",
      "speaker": "Speaker 1",
      "text": "transcribed text"
    }
  ]
}
PROMPT;
    }

    private function buildRetryPrompt(string $language): string
    {
        $hint = match ($language) {
            'ru'    => 'Russian audio.',
            'kz'    => 'Kazakh audio. Do NOT translate.',
            default => 'Russian or Kazakh audio.',
        };

        return "{$hint} Transcribe. Return ONLY valid JSON:\n" .
               '{"segments":[{"start":"HH:MM:SS.mmm","end":"HH:MM:SS.mmm","speaker":"Speaker 1","text":"..."}]}';
    }
}
