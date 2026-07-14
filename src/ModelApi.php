<?php
declare(strict_types=1);

/**
 * Client for the internal Model API gateway (Vertex AI / Gemini 3.5 Flash).
 *
 * The gateway accepts files only as fileUri — a Cloud Storage or public
 * HTTP(S) URL reachable from Google. Local files must be published first
 * (see Transcriber::publicUrlFor()).
 */
class ModelApi
{
    private const MAX_ATTEMPTS = 20;
    // 429 RESOURCE_EXHAUSTED is common — retry after 3-5 s, then back off
    // progressively. Audio requests are token-heavy and quota recovery has
    // been observed to take several minutes, so the tail is long (60 s each).
    private const RETRY_DELAYS = [3, 5, 5, 5, 10, 15, 20, 30, 45, 60];

    /** @var callable|null fn(int $attempt, int $maxAttempts): void — called before each retry wait */
    private $onRetry = null;

    public function __construct(private string $baseUrl) {}

    public function onRetry(?callable $callback): void
    {
        $this->onRetry = $callback;
    }

    /**
     * POST /api/v1/gemini/generate — returns the generated text.
     * Retries on 429 (quota) and 5xx.
     */
    public function generate(
        string $text,
        ?array $fileData = null,
        string $thinkingLevel = 'MINIMAL',
        int    $maxOutputTokens = 16384,
        float  $temperature = 0.0
    ): string {
        $body = [
            'text'            => $text,
            'thinkingLevel'   => $thinkingLevel,
            'temperature'     => $temperature,
            'maxOutputTokens' => $maxOutputTokens,
        ];
        if ($fileData !== null) {
            $body['fileData'] = $fileData;
        }
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

        $lastError = '';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                if ($this->onRetry !== null) {
                    ($this->onRetry)($attempt, self::MAX_ATTEMPTS);
                }
                sleep(self::RETRY_DELAYS[$attempt - 2] ?? 60);
            }

            $ch = curl_init(rtrim($this->baseUrl, '/') . '/api/v1/gemini/generate');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 600,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => $payload,
            ]);
            $raw      = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);

            if ($raw === false) {
                $lastError = "network error: {$curlErr}";
                continue;
            }

            $data = json_decode((string)$raw, true);

            if ($httpCode === 200 && isset($data['text'])) {
                return (string)$data['text'];
            }

            $detail    = $data['detail'] ?? null;
            $code      = is_array($detail) ? (int)($detail['code'] ?? $httpCode) : $httpCode;
            $lastError = "HTTP {$httpCode}: " . substr((string)$raw, 0, 500);

            // 429 / 5xx are transient — retry; anything else is permanent
            if ($code !== 429 && $code < 500) {
                throw new RuntimeException("Model API error {$lastError}");
            }
        }

        throw new RuntimeException('Model API failed after ' . self::MAX_ATTEMPTS . " attempts: {$lastError}");
    }

    /**
     * Transcribe audio available at a public URL. Returns array of segment objects.
     */
    public function transcribeUrl(string $fileUrl, string $mimeType, string $language = 'auto'): array
    {
        $fileData = ['mimeType' => $mimeType, 'fileUri' => $fileUrl];

        $segments = $this->tryTranscribe($this->buildPrompt($language), $fileData);

        if ($segments === null) {
            // One retry with a stricter, minimal prompt
            $segments = $this->tryTranscribe($this->buildRetryPrompt($language), $fileData);
        }

        if ($segments === null) {
            throw new RuntimeException('Model API returned invalid JSON after retry');
        }
        return $segments;
    }

    private function tryTranscribe(string $prompt, array $fileData): ?array
    {
        $text = $this->generate($prompt, $fileData);

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
     * Summarize a speech transcript. Returns plain-text summary in the
     * transcript's language.
     */
    public function summarize(string $transcript, string $language = 'auto'): string
    {
        // Guard against extreme inputs; Gemini Flash handles this size easily
        if (mb_strlen($transcript) > 100000) {
            $transcript = mb_substr($transcript, 0, 100000);
        }

        $langHint = match ($language) {
            'ru'    => 'Write the summary in Russian.',
            'kz'    => 'Write the summary in Kazakh.',
            default => 'Write the summary in the same language as the transcript.',
        };

        $prompt = <<<PROMPT
You are an expert at summarizing speech transcripts (meetings, interviews, lectures, voice messages).

Transcript:
---
{$transcript}
---

Task: write a concise summary of this speech. {$langHint}

Format (plain text, NO markdown symbols like ** or ##):
- Start with a 1-2 sentence overview of what the recording is about.
- Then key points, each on its own line starting with "• ".
- If the speech contains decisions, agreements or action items, list them at the end under a line "Решения и задачи:" (or its equivalent in the transcript language).

Keep the whole summary under 250 words. Return ONLY the summary text.
PROMPT;

        return trim($this->generate($prompt, null, 'LOW', 4096, 0.2));
    }

    /**
     * Add punctuation to Kazakh text using its Russian translation as reference.
     * Segment separator ||| must be preserved in output.
     */
    public function addPunctuation(string $kazakhText, string $russianText): string
    {
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

        $result = $this->generate($prompt, null, 'LOW');
        return trim($result) !== '' ? trim($result) : $kazakhText;
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
- Transcribe ONLY speech that actually occurs in the audio. Never invent,
  extend, or repeat content. When the audio ends — stop.
- Add correct punctuation and fix obvious spelling mistakes.
- Segment by complete thoughts, NOT by sentence: group consecutive sentences
  of the same speaker into one segment of roughly 15-30 seconds. Start a new
  segment only on a speaker change, a long pause, or a clear topic shift.
- Use timestamps in HH:MM:SS.mmm format.
- Return ONLY valid JSON. No markdown. No extra text.

Speaker diarization — CRITICAL, be very precise:
- Distinguish speakers ONLY by VOICE characteristics: pitch (high/low), timbre
  (tone quality), speaking pace (fast/slow), accent, breathing patterns.
- Number speakers by order of first appearance: "Speaker 1" = whoever speaks
  first, "Speaker 2" = next distinct voice, "Speaker 3" = third distinct voice, etc.
- When the same voice returns later (even after long silence), reuse its number.
- Do NOT confuse similar voices. If uncertain about a voice change, assume NEW speaker.
- Include all interjections/backchannel words ("да", "ага", "угу", "mhm") as
  separate segments if a different voice says them.
- If multiple speakers are present, track each one consistently throughout.
  Do NOT assign the same number to different speakers or different numbers to
  the same speaker.
- The first ~5 seconds may overlap with previous chunk. If you recognize a
  voice from before, REUSE its speaker number for consistency.
- For EVERY segment, also classify the speaker's perceived gender from voice
  pitch/timbre alone: "M" (male) or "F" (female). Gender never changes for the
  same speaker number — use it as an extra sanity check: if a voice you were
  about to label with an existing speaker number sounds like the opposite
  gender, it is almost certainly a NEW speaker, not a returning one.

Output format:
{
  "segments": [
    {
      "start": "HH:MM:SS.mmm",
      "end": "HH:MM:SS.mmm",
      "speaker": "Speaker 1",
      "gender": "M",
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

        return "{$hint} Transcribe. Distinguish speakers by voice, number them " .
               "by order of first appearance, reuse the same number for a " .
               "returning voice. Also classify each speaker's gender (M/F) from " .
               "voice pitch/timbre — gender never changes for the same speaker " .
               "number. Return ONLY valid JSON:\n" .
               '{"segments":[{"start":"HH:MM:SS.mmm","end":"HH:MM:SS.mmm","speaker":"Speaker 1","gender":"M","text":"..."}]}';
    }

    /**
     * Identify WHO is speaking WHEN in an audio clip, without transcribing
     * text. Returns array of turns: [{start, end, speaker}], or [] if parsing fails
     * (diarization is a nice-to-have — callers must degrade gracefully).
     */
    public function diarize(string $fileUrl, string $mimeType): array
    {
        $fileData = ['mimeType' => $mimeType, 'fileUri' => $fileUrl];
        $prompt   = <<<PROMPT
Listen to this audio clip and identify WHO is speaking WHEN. Do not transcribe
the words — only the speaker turns.

CRITICAL: Distinguish speakers by VOICE CHARACTERISTICS ALONE:
- Pitch (fundamental frequency): how high or low the voice is
- Timbre (tone quality): the unique "color" of the voice
- Speaking pace: fast, slow, or moderate talker
- Accent/pronunciation patterns: how words are articulated
- Breathing patterns and pauses: unique to each person

STRICT RULES:
1. Listen for ANY change in these voice characteristics to detect a new speaker.
2. When you hear a distinctly different voice, assign it a NEW speaker number.
3. When you hear the same voice again (even after silence), REUSE its number.
4. Do NOT rely on sentence structure or topic change — ONLY voice characteristics.
5. When in doubt (unclear voice characteristic change), default to NEW speaker.
6. Include short interjections/backchannel words as separate turns if different voice.
7. Cover entire clip with contiguous turns (no gaps, no overlaps).
8. Use HH:MM:SS.mmm timestamps, relative to clip start.
9. For EVERY turn, also classify the speaker's perceived gender from voice
   pitch/timbre alone: "M" (male) or "F" (female). Gender never changes for
   the same speaker number — use it as an extra sanity check: if a voice you
   were about to label with an existing speaker number sounds like the
   opposite gender, it is almost certainly a NEW speaker, not a returning one.
10. Return ONLY valid JSON, no markdown.

EXAMPLE (3 speakers):
Speaker 1, M (low pitch, slow): 00:00-00:15
Speaker 2, F (high pitch, fast): 00:15-00:22
Speaker 3, M (medium pitch, moderate): 00:22-00:35
Speaker 1, M (low pitch, same as before): 00:35-00:50

Output format:
{"turns": [{"start": "HH:MM:SS.mmm", "end": "HH:MM:SS.mmm", "speaker": "Speaker 1", "gender": "M"}]}
PROMPT;

        // Turns are compact (no transcript text), but a long, chatty
        // recording with rapid back-and-forth can still produce hundreds of
        // them — 4096 tokens truncates well before the file ends, silently
        // dropping the tail of the speaker map.
        $text = $this->generate($prompt, $fileData, 'MINIMAL', 65536, 0.0);
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', $text);

        $parsed = json_decode(trim($text), true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['turns']) || !is_array($parsed['turns'])) {
            return [];
        }
        return $parsed['turns'];
    }
}
