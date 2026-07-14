#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone diarization test harness — does NOT touch production code.
 * Runs several diarization configurations (prompt variant x chunking
 * strategy) against the 3 sample files in test/, and saves every raw
 * response under test/results/ so they can be compared by hand against
 * the known ground truth speaker counts.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/ModelApi.php';
require_once __DIR__ . '/../src/RecreateApi.php';

$testDir    = __DIR__;
$resultsDir = __DIR__ . '/results';
if (!is_dir($resultsDir)) mkdir($resultsDir, 0755, true);

$model    = new ModelApi(MODEL_API_URL);
$recreate = new RecreateApi();

// Lab-only: NO retry at all. A single 429/5xx immediately skips this config
// and moves on — we'd rather have gaps in the results matrix than stall the
// whole run waiting on quota.
function labGenerate(string $baseUrl, string $prompt, array $fileData): string
{
    $body = [
        'text'            => $prompt,
        'thinkingLevel'   => 'MINIMAL',
        'temperature'     => 0.0,
        'maxOutputTokens' => 65536,
        'fileData'        => $fileData,
    ];
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

    $ch = curl_init(rtrim($baseUrl, '/') . '/api/v1/gemini/generate');
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
        throw new RuntimeException("network error: {$curlErr}");
    }

    $data = json_decode((string)$raw, true);
    if ($httpCode === 200 && isset($data['text'])) {
        return (string)$data['text'];
    }

    if ($httpCode === 429) {
        throw new RuntimeException('quota exhausted (429) — skipped, no retry');
    }
    throw new RuntimeException("HTTP {$httpCode}: " . substr((string)$raw, 0, 300));
}

$files = [
    'abdr_man' => [
        'path'              => $testDir . '/abdr_man___ИНТЕРВЬЮ___ВИДЕОМОНТАЖ_ЖӘНЕ_ТИКТОК_ТУРАЛЫ (mp3cut.net).m4a',
        'expected_speakers' => 2,
        'note'              => 'Interview between two people',
    ],
    'kyzym' => [
        'path'              => $testDir . '/«ҚЫЗЫМ»_подкаст___Жазира_Еркін_&_INTELLIGENTNO_QU (mp3cut.net) (1).m4a',
        'expected_speakers' => 5,
        'note'              => 'Podcast, 5 speakers',
    ],
    'kudyrtu' => [
        'path'              => $testDir . '/Қудырту_#1_Руслан,_Аңсаған,_Тілеген,_Талғат,_Жоламан_ (mp3cut.net).m4a',
        'expected_speakers' => 4,
        'note'              => 'Group discussion, 4 speakers (5 names in title)',
    ],
];

// -----------------------------------------------------------------------
// Publish local files to public URLs (cached across reruns)
// -----------------------------------------------------------------------
function publishLocal(RecreateApi $recreate, string $filePath, array &$urlCache, string $cacheFile): array
{
    if (isset($urlCache[$filePath])) {
        return $urlCache[$filePath];
    }

    $tmpPath = sys_get_temp_dir() . '/diarlab_' . uniqid() . '.mp4';
    $head    = (string)@file_get_contents($filePath, false, null, 0, 12);
    if (substr($head, 4, 4) === 'ftyp') {
        copy($filePath, $tmpPath);
    } else {
        exec('afconvert -f m4af -d aac ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tmpPath) . ' 2>&1', $out, $code);
        if ($code !== 0 || !file_exists($tmpPath)) {
            throw new RuntimeException("afconvert failed for {$filePath}");
        }
    }

    try {
        $res = $recreate->extractAudio($tmpPath, 'video/mp4');
    } finally {
        @unlink($tmpPath);
    }

    $entry = ['url' => $res['url'], 'duration' => $res['duration']];
    $urlCache[$filePath] = $entry;
    file_put_contents($cacheFile, json_encode($urlCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $entry;
}

function tsToSecs(string $ts): float
{
    $p = explode(':', $ts);
    return (float)($p[0] ?? 0) * 3600 + (float)($p[1] ?? 0) * 60 + (float)($p[2] ?? 0);
}

function secsToTs(float $s): string
{
    $h  = (int)($s / 3600);
    $m  = (int)(fmod($s, 3600) / 60);
    $sc = fmod($s, 60);
    return sprintf('%02d:%02d:%06.3f', $h, $m, $sc);
}

function parseTurns(string $text): array
{
    $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
    $text = preg_replace('/\s*```$/i', '', $text);
    $parsed = json_decode(trim($text), true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['turns']) || !is_array($parsed['turns'])) {
        return [];
    }
    return $parsed['turns'];
}

function summarizeTurns(array $turns): array
{
    $speakers = [];
    $genders  = [];
    foreach ($turns as $t) {
        $sp = $t['speaker'] ?? '?';
        $dur = tsToSecs($t['end'] ?? '0') - tsToSecs($t['start'] ?? '0');
        $speakers[$sp] = ($speakers[$sp] ?? 0) + max(0, $dur);
        if (!empty($t['gender'])) {
            $genders[$sp] = $t['gender'];
        }
    }
    return [
        'turn_count'        => count($turns),
        'distinct_speakers' => count($speakers),
        'speaker_seconds'   => $speakers,
        'speaker_genders'   => $genders,
    ];
}

// -----------------------------------------------------------------------
// Prompt variants
// -----------------------------------------------------------------------

// V1: current production prompt (as shipped in ModelApi::diarize as of this test)
function promptV1(): string
{
    return <<<PROMPT
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
}

// V2: "count first" two-pass strategy — force an explicit inventory of voices
// before assigning any turns, to fight the "similar voice gets folded into an
// existing speaker" failure mode with 3+ speakers.
function promptV2(): string
{
    return <<<PROMPT
You will identify WHO is speaking WHEN in this audio clip. Do not transcribe
words — only speaker turns. Work in two explicit passes.

PASS 1 — Voice inventory (do this mentally before answering):
- Listen to the ENTIRE clip first.
- Count how many genuinely DIFFERENT voices are present. Do not undercount:
  two voices that sound somewhat similar are still different people if their
  pitch, timbre, pace, or accent differ even slightly.
- Do not overcount either: the same person's voice can vary a little when they
  raise their voice, laugh, or trail off — that is still the same speaker.
- Fix the total number of speakers N before doing pass 2.

PASS 2 — Turn assignment:
- Go through the clip chronologically and label every turn with one of the N
  speakers you identified in pass 1.
- "Speaker 1" = whoever speaks first, "Speaker 2" = the next NEW voice to
  appear, etc. Never introduce a speaker number beyond N.
- When a voice returns later in the clip, you MUST reuse its original number —
  re-check pitch/timbre/pace against your pass-1 inventory before deciding.
- Include short interjections/backchannel words as their own turn if a
  different voice says them.
- Cover the entire clip with contiguous turns (no gaps, no overlaps).
- Use HH:MM:SS.mmm timestamps relative to clip start.
- For EVERY turn, also classify the speaker's perceived gender from voice
  pitch/timbre alone: "M" (male) or "F" (female). Gender never changes for the
  same speaker number — cross-check it against your pass-1 inventory: a voice
  that sounds like the opposite gender of an existing speaker number is
  almost certainly a NEW speaker, not a returning one.

Return ONLY valid JSON, no markdown, no explanation of your passes:
{"turns": [{"start": "HH:MM:SS.mmm", "end": "HH:MM:SS.mmm", "speaker": "Speaker 1", "gender": "M"}]}
PROMPT;
}

$promptVariants = ['v1_voice_characteristics' => 'promptV1', 'v2_count_first' => 'promptV2'];

// -----------------------------------------------------------------------
// Run tests
// -----------------------------------------------------------------------
$urlCacheFile = $resultsDir . '/_url_cache.json';
$urlCache     = file_exists($urlCacheFile) ? (json_decode(file_get_contents($urlCacheFile), true) ?: []) : [];

$summary = [];

foreach ($files as $key => $info) {
    fwrite(STDERR, "\n=== {$key} ({$info['note']}, expected {$info['expected_speakers']} speakers) ===\n");

    fwrite(STDERR, "Publishing to public URL...\n");
    $published = publishLocal($recreate, $info['path'], $urlCache, $urlCacheFile);
    $url       = $published['url'];
    $duration  = (float)$published['duration'];
    fwrite(STDERR, "  URL: {$url}\n  duration: {$duration}s\n");

    $mimeType = 'audio/mpeg';

    // --- Whole-file diarization, both prompt variants ---
    foreach ($promptVariants as $variantKey => $fnName) {
        $outFile = $resultsDir . "/{$key}__wholefile__{$variantKey}.json";
        if (file_exists($outFile)) {
            $cached = json_decode(file_get_contents($outFile), true);
            $summary[$key]["wholefile/{$variantKey}"] = $cached['stats'] ?? ['error' => 'cached but unreadable'];
            fwrite(STDERR, "Whole-file diarize [{$variantKey}]: already done, skipping ({$cached['stats']['turn_count']} turns, {$cached['stats']['distinct_speakers']} speakers)\n");
            continue;
        }
        fwrite(STDERR, "Whole-file diarize [{$variantKey}]...\n");
        try {
            $text  = labGenerate(MODEL_API_URL, $fnName(), ['mimeType' => $mimeType, 'fileUri' => $url]);
            $turns = parseTurns($text);
            $stats = summarizeTurns($turns);
            file_put_contents(
                $outFile,
                json_encode(['turns' => $turns, 'stats' => $stats], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $summary[$key]["wholefile/{$variantKey}"] = $stats;
            fwrite(STDERR, "  -> {$stats['turn_count']} turns, {$stats['distinct_speakers']} distinct speakers\n");
        } catch (Throwable $e) {
            fwrite(STDERR, "  SKIPPED (quota stuck): " . $e->getMessage() . "\n");
            $summary[$key]["wholefile/{$variantKey}"] = ['error' => $e->getMessage()];
        }
    }

    // --- Chunked diarization at 60s, both prompt variants ---
    // Illustrates the fragmentation problem: each chunk is diarized in
    // isolation (no cross-chunk context), so speaker numbering can drift.
    $chunkDur = 60;
    $overlap  = 5;
    $numChunks = (int)ceil($duration / $chunkDur);

    foreach ($promptVariants as $variantKey => $fnName) {
        $chunkOutFile = $resultsDir . "/{$key}__chunked60__{$variantKey}.json";
        if (file_exists($chunkOutFile)) {
            $chunkResults = json_decode(file_get_contents($chunkOutFile), true) ?: [];
            fwrite(STDERR, "Chunked diarize (60s chunks) [{$variantKey}]: already done, skipping (" . count($chunkResults) . " chunks)\n");
        } else {
            fwrite(STDERR, "Chunked diarize (60s chunks) [{$variantKey}]...\n");
            $chunkResults = [];
            for ($i = 0, $start = 0; $start < $duration; $start += $chunkDur, $i++) {
                $end = min($start + $chunkDur + $overlap, $duration);
                try {
                    $chunkUrl = $recreate->cutAudio($url, (int)($start * 1000), (int)($end * 1000));
                    $text     = labGenerate(MODEL_API_URL, $fnName(), ['mimeType' => $mimeType, 'fileUri' => $chunkUrl]);
                    $turns    = parseTurns($text);
                    $stats    = summarizeTurns($turns);
                    $chunkResults[] = [
                        'chunk_index' => $i,
                        'start'       => $start,
                        'end'         => $end,
                        'turns'       => $turns,
                        'stats'       => $stats,
                    ];
                    fwrite(STDERR, "  chunk {$i} ({$start}-{$end}s): {$stats['turn_count']} turns, {$stats['distinct_speakers']} distinct speakers\n");
                } catch (Throwable $e) {
                    fwrite(STDERR, "  chunk {$i} SKIPPED (quota stuck): " . $e->getMessage() . "\n");
                    $chunkResults[] = ['chunk_index' => $i, 'start' => $start, 'end' => $end, 'error' => $e->getMessage()];
                }
            }

            file_put_contents(
                $chunkOutFile,
                json_encode($chunkResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        // Aggregate: how many distinct speaker LABELS were used across chunks
        // (a naive count — since each chunk numbers fresh, this number is
        // expected to be inflated vs the true speaker count; that inflation
        // is exactly the bug whole-file diarization fixes)
        $allLabels = [];
        foreach ($chunkResults as $cr) {
            foreach ($cr['stats']['speaker_seconds'] ?? [] as $sp => $secs) {
                $allLabels[$sp] = true;
            }
        }
        $summary[$key]["chunked60/{$variantKey}"] = [
            'chunks'                      => $numChunks,
            'raw_label_count_no_merging'  => count($allLabels),
            'note'                        => 'labels reset per chunk; not directly comparable to whole-file distinct_speakers',
        ];
    }
}

file_put_contents(
    $resultsDir . '/_summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

fwrite(STDERR, "\n=== DONE. Results in test/results/ ===\n");
fwrite(STDERR, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
