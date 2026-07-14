<?php
declare(strict_types=1);

class Transcriber
{
    private int $currentJobId = 0;

    /** @var int[] chunk numbers that failed all retries during the last chunked run */
    private array $failedChunks = [];

    public function __construct(
        private ModelApi     $model,
        private Database     $db,
        private MangisozApi  $mangisoz,
        private RecreateApi  $recreate,
        private PdfGenerator $pdf = new PdfGenerator(),
    ) {}

    /**
     * Main pipeline: upload → transcribe → summarize → save.
     * Progress is reported via the job's status_message column (polled by the web UI).
     */
    public function process(int $jobId): void
    {
        $this->currentJobId = $jobId;
        $this->failedChunks = [];
        $job = $this->db->getJob($jobId);

        // Surface gateway quota waits (429 retries) in the live status
        $this->model->onRetry(function (int $attempt, int $max) {
            $this->status("⏳ Модель перегружена, ждём квоту (попытка {$attempt}/{$max})...");
        });

        try {
            $filePath = $job['original_file_path'];
            $ext      = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = $this->mimeType($ext);
            $language = $job['language'];
            $duration = (int)($job['duration'] ?? 0);

            $this->db->updateJob($jobId, ['status' => 'processing']);
            $this->setProgress(5);

            $fileUrl = null; // public URL used for cloud operations (chunking)

            // Extract audio from video before transcription
            if (in_array($ext, SUPPORTED_VIDEO, true)) {
                $this->status('🎬 Извлекаем аудио из видео...');
                $this->setProgress(10);

                // Recreate sits behind Cloudflare, which rejects large uploads
                // (HTTP 413) — for anything but tiny clips, pull the audio
                // track out locally first (afconvert) so only a small,
                // speech-optimized file goes over the wire.
                $sourcePath = $filePath;
                $bridgePath = null;
                if ((int)@filesize($filePath) > 30 * 1024 * 1024) {
                    $bridgePath = $this->compressAudioTrack($jobId, $filePath);
                    if ($bridgePath !== null) {
                        $sourcePath = $bridgePath;
                    }
                }

                try {
                    $extracted = $this->recreate->extractAudio($sourcePath, $bridgePath !== null ? 'video/mp4' : $mimeType);
                } finally {
                    if ($bridgePath !== null) @unlink($bridgePath);
                }
                $extractedUrl = $extracted['url'];
                if ($extracted['duration'] > 0) {
                    $duration = (int)ceil($extracted['duration']);
                }

                // Upload extracted audio to cloud storage immediately for a stable URL
                $this->status('☁️ Загружаем аудио в облако...');
                $fileUrl = $this->recreate->uploadFileByUrl($extractedUrl);

                // Download extracted audio locally (for Mangisoz direct calls)
                $audioPath = STORAGE_PATH . '/uploads/job_' . $jobId . '_audio.mp3';
                $bytes     = file_get_contents($extractedUrl);
                if ($bytes === false) {
                    throw new RuntimeException("Не удалось скачать аудио: {$extractedUrl}");
                }
                file_put_contents($audioPath, $bytes);

                $filePath = $audioPath;
                $ext      = 'mp3';
                $mimeType = 'audio/mpeg';

                // Web uploads have no duration metadata — estimate from file size
                if ($duration === 0) {
                    $duration = $this->estimateDuration($audioPath, 'mp3');
                }
            }

            // For web audio uploads with unknown duration, estimate from file size
            if ($duration === 0 && $filePath !== '' && file_exists($filePath)) {
                $duration = $this->estimateDuration($filePath, $ext);
            }

            // Engine: for kz/ru the user chooses Mangisoz (default) or Gemini;
            // auto always goes to the Model API gateway (Gemini).
            // Mangisoz internally segments at ~28 s — keep chunks shorter to avoid word loss.
            $engine      = $job['engine'] ?? 'mangisoz';
            $useMangisoz = in_array($language, ['kz', 'ru'], true) && $engine !== 'gemini';
            $effectiveChunkDuration = $useMangisoz ? CHUNK_DURATION_KZ : CHUNK_DURATION;

            // The gateway accepts files only by public URL — publish local-only
            // files (web uploads) via Recreate before transcription.
            if (!$useMangisoz && $fileUrl === null && $filePath !== '' && file_exists($filePath)) {
                $this->status('☁️ Загружаем файл в облако...');
                [$fileUrl, $ext, $mimeType, $realDuration] = $this->publishLocalAudio($jobId, $filePath, $ext, $mimeType);
                // The publish step reports the true duration — always better than
                // the file-size estimate, which skews chunk boundaries
                if ($realDuration > 0) {
                    $duration = (int)ceil($realDuration);
                }
            }

            // For Mangisoz web uploads (no public URL): chunk locally via afconvert + WAV split.
            $useLocalChunks = $useMangisoz
                && $fileUrl === null
                && $filePath !== ''
                && file_exists($filePath)
                && $duration > CHUNK_DURATION_KZ;

            $useChunks = $duration > $effectiveChunkDuration && $fileUrl !== null;

            if ($useLocalChunks) {
                $total = (int)ceil($duration / CHUNK_DURATION_KZ);
                $this->status("🧠 Транскрибация (Mangisoz): {$total} " . $this->pluralPart($total) . "...");
                $this->db->updateJob($jobId, ['status' => 'transcribing']);
                $this->setProgress(20);
                $segments = $this->transcribeLocalChunkedKz($jobId, $filePath, $mimeType, $duration, $language);
            } elseif ($useChunks) {
                $stableUrl   = $fileUrl;
                $totalChunks = (int)ceil($duration / $effectiveChunkDuration);
                $label       = $useMangisoz ? 'Mangisoz' : 'Gemini';
                $this->status("🧠 Транскрибация ({$label}): {$totalChunks} " . $this->pluralPart($totalChunks) . "...");
                $this->db->updateJob($jobId, ['status' => 'transcribing']);
                $this->setProgress(20);
                $segments = $this->transcribeChunked($jobId, $stableUrl, $ext, $mimeType, $language, $duration, $effectiveChunkDuration, $useMangisoz);
            } elseif ($useMangisoz) {
                $this->status('🧠 Делаем транскрибацию (Mangisoz)... Это может занять несколько минут.');
                $this->db->updateJob($jobId, ['status' => 'transcribing']);
                $this->setProgress(20);
                $segments = $this->mangisoz->transcribe($filePath, $mimeType, $language);

                // Mangisoz's STT carries no speaker info — ask Gemini separately.
                // Diarization needs a public URL; publish one if we don't have it yet.
                $diarizeUrl  = $fileUrl;
                $diarizeMime = $mimeType;
                if ($diarizeUrl === null) {
                    try {
                        [$diarizeUrl, , $diarizeMime] = $this->publishLocalAudio($jobId, $filePath, $ext, $mimeType);
                    } catch (Throwable $e) {
                        error_log("[Job {$jobId}] diarization publish skipped: " . $e->getMessage());
                    }
                }
                if ($diarizeUrl !== null) {
                    $this->status('🗣️ Определяем спикеров...');
                    $turns    = $this->diarizeSafely($diarizeUrl, $diarizeMime);
                    $segments = $this->assignSpeakersByOverlap($segments, $turns);
                }
            } else {
                $this->db->updateJob($jobId, ['status' => 'transcribing']);
                $this->status('🧠 Делаем транскрибацию... Это может занять несколько минут.');
                $segments = $this->model->transcribeUrl($fileUrl, $mimeType, $language);
            }

            $this->db->updateJob($jobId, ['status' => 'postprocessing']);
            $this->setProgress(75);

            // Summarize only if mode is 'summary' (краткое содержание)
            $summary = null;
            $jobMode = $job['mode'] ?? 'text';
            if ($jobMode === 'summary') {
                $this->status('📝 Составляем резюме...');
                try {
                    $summary = $this->model->summarize($this->formatTxt($segments), $language);
                } catch (Throwable $e) {
                    error_log("[Job {$jobId}] summary failed: " . $e->getMessage());
                }
            }

            $this->status('📝 Сохраняем результаты...');
            $this->setProgress(85);

            [$txtPath, $jsonPath, $pdfPath] = $this->saveResults($jobId, $segments, $language, $summary);
            $this->setProgress(95);

            $update = [
                'status'           => 'done',
                'result_txt_path'  => $txtPath,
                'result_json_path' => $jsonPath,
                'summary'          => $summary,
            ];
            if ($pdfPath !== null) {
                $update['result_pdf_path'] = $pdfPath;
            }
            $this->db->updateJob($jobId, $update);

            if (!empty($this->failedChunks)) {
                $n = count($this->failedChunks);
                $this->status("✅ Готово (⚠️ {$n} " . $this->pluralPart($n) . " не удалось распознать и они пропущены)");
            } else {
                $this->status('✅ Готово!');
            }

        } catch (Throwable $e) {
            error_log("[Job {$jobId}] " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->db->updateJob($jobId, ['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // Chunked transcription

    /**
     * Split audio into CHUNK_DURATION-second pieces with CHUNK_OVERLAP-second overlap,
     * transcribe each piece, adjust timestamps, and merge segments.
     */
    private function transcribeChunked(
        int    $jobId,
        string $tgUrl,
        string $ext,
        string $mimeType,
        string $language,
        int    $duration,
        int    $chunkDuration = CHUNK_DURATION,
        bool   $useMangisoz = false
    ): array {
        $total       = (int)ceil($duration / $chunkDuration);
        $all         = [];
        $chunkNum    = 0;
        $prevChunkOk = true; // whether the immediately preceding chunk actually produced segments

        // Neither engine's per-chunk speaker labels are trustworthy across
        // chunk boundaries: Mangisoz has no speaker info at all, and even the
        // Model API/Gemini path numbers speakers fresh within each isolated
        // chunk ("Speaker 1" = whichever voice it hears first in THAT slice),
        // so a turn split across a chunk seam can flip identities for the
        // rest of the chunk. Prefer ONE diarization pass over the whole file:
        // with full context the model tracks voices far more reliably than
        // when it only hears an isolated 20-60s slice (verified: an
        // independent per-chunk call mislabeled a single speaker as
        // "Speaker 2" mid-file, while a whole-file call got it right).
        // Diarization output is just turn boundaries (no transcript text), so
        // it stays cheap even for long recordings — only fall back to
        // per-chunk diarization when the whole-file pass genuinely fails
        // (bad JSON, truncated output, network error).
        $wholeFileTurns = null;
        if ($duration <= 5400) {
            $this->status('🗣️ Определяем спикеров...');
            $turns = $this->diarizeSafely($tgUrl, $mimeType);
            if (!empty($turns)) {
                $wholeFileTurns = $turns;
            }
        }

        for ($start = 0; $start < $duration; $start += $chunkDuration) {
            $chunkNum++;
            $end = min($start + $chunkDuration + CHUNK_OVERLAP, $duration);

            $this->status("🧠 Часть {$chunkNum}/{$total}: нарезаем...");

            $chunkUrl = $this->recreate->cutAudio($tgUrl, $start * 1000, $end * 1000);

            $this->status("🧠 Часть {$chunkNum}/{$total}: транскрибируем...");

            // A single flaky chunk (bad JSON, transient network hiccup) must not
            // sink an entire long file — retry a couple of times, then skip it
            // and keep going so the rest of the transcript still comes through.
            $segments = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    if ($useMangisoz) {
                        // Mangisoz needs the file bytes — download the chunk locally
                        $chunkPath = STORAGE_PATH . "/uploads/chunk_{$jobId}_{$chunkNum}.{$ext}";
                        $bytes     = file_get_contents($chunkUrl);
                        if ($bytes === false) {
                            throw new RuntimeException("Не удалось скачать chunk {$chunkNum}: {$chunkUrl}");
                        }
                        file_put_contents($chunkPath, $bytes);

                        try {
                            $segments = $this->mangisoz->transcribe($chunkPath, $mimeType, $language);
                        } finally {
                            @unlink($chunkPath);
                        }
                    } else {
                        // The Model API gateway consumes the chunk directly by URL
                        $segments = $this->model->transcribeUrl($chunkUrl, $mimeType, $language);
                    }
                    break;
                } catch (Throwable $e) {
                    error_log("[Job {$jobId}] chunk {$chunkNum}/{$total} attempt {$attempt} failed: " . $e->getMessage());
                    if ($attempt < 3) {
                        $this->status("⏳ Часть {$chunkNum}/{$total}: сбой, повторяем ({$attempt}/3)...");
                        sleep(5);
                    }
                }
            }

            if ($segments === null) {
                $this->failedChunks[] = $chunkNum;
                $prevChunkOk = false; // no tail was captured — the next chunk must not assume overlap coverage
                continue; // give up on this chunk, keep transcribing the rest
            }

            // Only diarize per-chunk when a whole-file pass wasn't attempted
            // or didn't return anything usable (long file, or transient failure)
            if ($wholeFileTurns === null) {
                $turns    = $this->diarizeSafely($chunkUrl, $mimeType);
                $segments = $this->assignSpeakersByOverlap($segments, $turns);
            }

            $chunkLen  = $end - $start; // actual seconds of audio in this chunk
            $hasOverlap = $chunkNum > 1 && $prevChunkOk;

            // Absolute time up to which content has actually been captured so
            // far. The previous chunk doesn't always get transcribed all the
            // way to its nominal end (the model can stop a few seconds early),
            // so the overlap window isn't reliably "already covered" — using
            // the real coverage boundary instead of the nominal one prevents
            // genuinely new speech near the seam from being silently dropped.
            $prevCoverageEnd = empty($all) ? 0.0 : $this->tsToSecs(end($all)['end']);

            foreach ($segments as $seg) {
                $segStartRel = $this->tsToSecs($seg['start'] ?? '00:00:00.000');
                $segEndRel   = $this->tsToSecs($seg['end']   ?? '00:00:00.000');

                // Drop hallucinated segments: the model sometimes "continues"
                // past the real end of a short chunk, repeating earlier content
                if ($segStartRel >= $chunkLen + 1) {
                    continue;
                }
                $segEndRel = min($segEndRel, (float)$chunkLen);

                $absStart = $start + $segStartRel;
                $absEnd   = $start + $segEndRel;

                // Skip only what's actually already covered by prior output —
                // not just "inside the nominal overlap window", which wrongly
                // drops real content whenever the previous chunk cut off early.
                if ($hasOverlap && $absEnd <= $prevCoverageEnd) {
                    continue;
                }

                $text = trim($seg['text'] ?? '');

                // Segments near the chunk start often repeat the previous chunk's
                // tail (overlap echo) — models also re-anchor slightly past the
                // overlap zone, so dedup a wider window, not just the overlap itself.
                if ($hasOverlap && $segStartRel < CHUNK_OVERLAP + 8) {
                    $text = $this->removeLeadingDuplicates($all, $text);
                    // Adjust timestamp to the real coverage boundary so there's no gap
                    if ($absStart < $prevCoverageEnd) {
                        $absStart = $prevCoverageEnd;
                    }
                }

                if ($text === '') continue;

                $all[] = [
                    'start'   => $this->secsToTs($absStart),
                    'end'     => $this->secsToTs($absEnd),
                    'speaker' => $seg['speaker'] ?? 'Speaker 1',
                    'gender'  => $seg['gender'] ?? null,
                    'text'    => $text,
                ];
            }

            $prevChunkOk = true;
        }

        // If we have whole-file diarization, apply it BEFORE merging so that
        // adjacent same-speaker segments (which had per-chunk labels) can be
        // properly merged. Otherwise merging can't combine a segment labeled
        // "Speaker 1" (from chunk 1) with "Speaker 2" (from chunk 2), even
        // if they're actually the same voice per whole-file diarization.
        if ($wholeFileTurns !== null) {
            $all = $this->assignSpeakersByOverlap($all, $wholeFileTurns);
        }

        $merged = $this->mergeAdjacentSegments($all);
        return $merged;
    }

    // -------------------------------------------------------------------------
    // Local chunking for kz web uploads (no public URL available)

    /**
     * Convert audio to WAV via afconvert, split into CHUNK_DURATION_KZ-second chunks
     * in pure PHP, transcribe each chunk with Mangisoz, and merge the results.
     * Falls back to single-file transcription if afconvert is unavailable.
     */
    private function transcribeLocalChunkedKz(
        int    $jobId,
        string $filePath,
        string $mimeType,
        int    $duration,
        string $language = 'kz'
    ): array {
        $wavPath = STORAGE_PATH . '/uploads/job_' . $jobId . '_tmp.wav';

        // Convert to 16-bit PCM WAV (afconvert is available on macOS)
        $cmd    = 'afconvert -f WAVE -d LEI16 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($wavPath) . ' 2>&1';
        $out    = [];
        $code   = 0;
        exec($cmd, $out, $code);

        if ($code !== 0 || !file_exists($wavPath)) {
            error_log("[Job {$jobId}] afconvert failed (code {$code}): " . implode(' ', $out) . ' — falling back to single-file transcription');
            return $this->mangisoz->transcribe($filePath, $mimeType, $language);
        }

        // Prefer diarizing the whole file in one Gemini pass — far more
        // reliable than isolated per-chunk calls (see transcribeChunked).
        // Diarization output is just turn boundaries, so it stays cheap even
        // for long recordings — only fall back to per-chunk diarization when
        // the whole-file pass genuinely fails.
        $wholeFileTurns = null;
        if ($duration <= 5400) {
            $this->status('🗣️ Определяем спикеров...');
            $diarizeUrl = $this->publishForDiarization($filePath);
            if ($diarizeUrl !== null) {
                $turns = $this->diarizeSafely($diarizeUrl, $mimeType);
                if (!empty($turns)) {
                    $wholeFileTurns = $turns;
                }
            }
        }

        $wavInfo = $this->parseWavHeader($wavPath);
        if ($wavInfo === null) {
            @unlink($wavPath);
            $segments = $this->mangisoz->transcribe($filePath, $mimeType, $language);
            return $wholeFileTurns !== null
                ? $this->assignSpeakersByOverlap($segments, $wholeFileTurns)
                : $segments;
        }

        $total       = (int)ceil($duration / CHUNK_DURATION_KZ);
        $all         = [];
        $chunkNum    = 0;
        $prevChunkOk = true; // whether the immediately preceding chunk actually produced segments

        for ($start = 0; $start < $duration; $start += CHUNK_DURATION_KZ) {
            $chunkNum++;
            $end       = min($start + CHUNK_DURATION_KZ + CHUNK_OVERLAP, $duration);
            $wavChunkPath = STORAGE_PATH . '/uploads/chunk_' . $jobId . '_' . $chunkNum . '.wav';
            $m4aChunkPath = STORAGE_PATH . '/uploads/chunk_' . $jobId . '_' . $chunkNum . '.m4a';

            $this->status("🧠 Часть {$chunkNum}/{$total}: транскрибируем...");
            $this->extractWavChunk($wavPath, $wavChunkPath, $wavInfo, $start, $end);

            // Skip empty chunks (can happen at EOF)
            if (!file_exists($wavChunkPath) || filesize($wavChunkPath) < 1000) {
                @unlink($wavChunkPath);
                $prevChunkOk = false;
                continue;
            }

            // Convert WAV chunk to M4A (AAC) — more compatible than raw WAV
            $convOut  = [];
            $convCode = 0;
            exec('afconvert -f m4af -d aac ' . escapeshellarg($wavChunkPath) . ' ' . escapeshellarg($m4aChunkPath) . ' 2>&1', $convOut, $convCode);
            @unlink($wavChunkPath);

            if ($convCode === 0 && file_exists($m4aChunkPath)) {
                $chunkPath = $m4aChunkPath;
                $chunkMime = 'audio/mp4';
            } else {
                error_log("[Job {$jobId}] afconvert m4a failed (code {$convCode}): " . implode(' ', $convOut));
                $this->extractWavChunk($wavPath, $wavChunkPath, $wavInfo, $start, $end);
                $chunkPath = $wavChunkPath;
                $chunkMime = 'audio/wav';
            }

            // Pause between requests — Mangisoz backend can 502 on rapid consecutive calls
            if ($chunkNum > 1) sleep(2);

            $segments = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $segments = $this->mangisoz->transcribe($chunkPath, $chunkMime, $language);
                    break;
                } catch (Throwable $e) {
                    error_log("[Job {$jobId}] chunk {$chunkNum}/{$total} attempt {$attempt} failed: " . $e->getMessage());
                    if ($attempt < 3) {
                        $this->status("⏳ Часть {$chunkNum}/{$total}: сбой, повторяем ({$attempt}/3)...");
                        sleep(5);
                    }
                }
            }

            // Only diarize this chunk individually when the whole-file pass
            // wasn't attempted or came back empty (long file, transient failure)
            $turns = [];
            if ($segments !== null && $wholeFileTurns === null) {
                $diarizeUrl = $this->publishForDiarization($chunkPath);
                if ($diarizeUrl !== null) {
                    $turns = $this->diarizeSafely($diarizeUrl, 'audio/mpeg');
                }
            }
            @unlink($chunkPath);

            if ($segments === null) {
                $this->failedChunks[] = $chunkNum;
                $prevChunkOk = false; // no tail was captured — the next chunk must not assume overlap coverage
                continue; // give up on this chunk, keep transcribing the rest
            }

            if ($wholeFileTurns === null) {
                $segments = $this->assignSpeakersByOverlap($segments, $turns);
            }
            $hasOverlap = $chunkNum > 1 && $prevChunkOk;

            // See transcribeChunked() — use actual captured coverage, not the
            // nominal overlap window, so a short-stopped previous chunk can't
            // cause real speech near the seam to be dropped by both chunks.
            $prevCoverageEnd = empty($all) ? 0.0 : $this->tsToSecs(end($all)['end']);

            foreach ($segments as $seg) {
                $segStartRel = $this->tsToSecs($seg['start'] ?? '00:00:00.000');
                $segEndRel   = $this->tsToSecs($seg['end']   ?? '00:00:00.000');

                $absStart = $start + $segStartRel;
                $absEnd   = $start + $segEndRel;

                if ($hasOverlap && $absEnd <= $prevCoverageEnd) continue;

                $text = trim($seg['text'] ?? '');

                if ($hasOverlap && $segStartRel < CHUNK_OVERLAP + 8) {
                    $text = $this->removeLeadingDuplicates($all, $text);
                    if ($absStart < $prevCoverageEnd) {
                        $absStart = $prevCoverageEnd;
                    }
                }

                if ($text === '') continue;

                $all[] = [
                    'start'   => $this->secsToTs($absStart),
                    'end'     => $this->secsToTs($absEnd),
                    'speaker' => $seg['speaker'] ?? 'Speaker 1',
                    'gender'  => $seg['gender'] ?? null,
                    'text'    => $text,
                ];
            }

            $prevChunkOk = true;
        }

        @unlink($wavPath);

        // If we have whole-file diarization, apply it BEFORE merging so that
        // adjacent same-speaker segments (which had per-chunk labels) can be
        // properly merged. Otherwise merging can't combine a segment labeled
        // "Speaker 1" (from chunk 1) with "Speaker 2" (from chunk 2), even
        // if they're actually the same voice per whole-file diarization.
        if ($wholeFileTurns !== null) {
            $all = $this->assignSpeakersByOverlap($all, $wholeFileTurns);
        }

        $merged = $this->mergeAdjacentSegments($all);
        return $merged;
    }

    /** Parse WAV RIFF header; returns null on failure. */
    private function parseWavHeader(string $wavPath): ?array
    {
        $fp = fopen($wavPath, 'rb');
        if (!$fp) return null;

        if (fread($fp, 4) !== 'RIFF') { fclose($fp); return null; }
        fread($fp, 4); // file size
        if (fread($fp, 4) !== 'WAVE') { fclose($fp); return null; }

        $sampleRate = $channels = $bitsPerSample = $dataOffset = $dataSize = 0;

        while (!feof($fp)) {
            $id  = fread($fp, 4);
            $raw = fread($fp, 4);
            if (strlen($id) < 4 || strlen($raw) < 4) break;
            $sz = unpack('V', $raw)[1];

            if ($id === 'fmt ') {
                fread($fp, 2); // audio format
                $channels      = unpack('v', fread($fp, 2))[1];
                $sampleRate    = unpack('V', fread($fp, 4))[1];
                fread($fp, 4); // byte rate
                fread($fp, 2); // block align
                $bitsPerSample = unpack('v', fread($fp, 2))[1];
                $extra = $sz - 16;
                if ($extra > 0) fread($fp, $extra);
            } elseif ($id === 'data') {
                $dataOffset = ftell($fp);
                $dataSize   = $sz;
                break;
            } else {
                $skip = $sz + ($sz % 2);
                if ($skip > 0) fread($fp, $skip);
            }
        }
        fclose($fp);

        if (!$sampleRate || !$dataOffset) return null;

        return [
            'sampleRate'    => $sampleRate,
            'channels'      => $channels,
            'bitsPerSample' => $bitsPerSample,
            'dataOffset'    => $dataOffset,
            'dataSize'      => $dataSize,
            'bytesPerSec'   => $sampleRate * $channels * intdiv($bitsPerSample, 8),
        ];
    }

    /** Write a slice of a WAV file's PCM data as a new valid WAV file. */
    private function extractWavChunk(string $src, string $dst, array $info, int $startSec, int $endSec): void
    {
        $bps       = $info['bytesPerSec'];
        $startByte = (int)($startSec * $bps);
        $endByte   = min((int)($endSec * $bps), $info['dataSize']);
        $pcmLen    = max(0, $endByte - $startByte);

        $in = fopen($src, 'rb');
        fseek($in, $info['dataOffset'] + $startByte);
        $pcm = $pcmLen > 0 ? fread($in, $pcmLen) : '';
        fclose($in);

        $blockAlign = $info['channels'] * intdiv($info['bitsPerSample'], 8);

        $out = fopen($dst, 'wb');
        fwrite($out, 'RIFF');
        fwrite($out, pack('V', 36 + $pcmLen));
        fwrite($out, 'WAVE');
        fwrite($out, 'fmt ');
        fwrite($out, pack('V', 16));
        fwrite($out, pack('v', 1));                        // PCM
        fwrite($out, pack('v', $info['channels']));
        fwrite($out, pack('V', $info['sampleRate']));
        fwrite($out, pack('V', $bps));
        fwrite($out, pack('v', $blockAlign));
        fwrite($out, pack('v', $info['bitsPerSample']));
        fwrite($out, 'data');
        fwrite($out, pack('V', $pcmLen));
        fwrite($out, $pcm);
        fclose($out);
    }

    /**
     * Translate Kazakh segments to Russian, then ask Gemini to add punctuation
     * to the Kazakh text using the Russian translation as a reference.
     */
    private function punctuateSegments(array $segments): array
    {
        if (empty($segments)) return $segments;

        $sep   = ' ||| ';
        $texts = array_map(fn($s) => $s['text'] ?? '', $segments);
        $kk    = implode($sep, $texts);

        // Translate kk → ru (Mangisoz), then use the Model API gateway to add punctuation
        $ru          = $this->mangisoz->translate($kk);
        $punctuated  = $this->model->addPunctuation($kk, $ru);

        $parts = array_map('trim', explode('|||', $punctuated));

        foreach ($segments as $i => &$seg) {
            if (isset($parts[$i]) && $parts[$i] !== '') {
                $seg['text'] = $parts[$i];
            }
        }

        return $segments;
    }

    /**
     * Pull just the audio track out of a (possibly huge) local video file and
     * re-encode it at a speech-optimized low bitrate, so it fits under the
     * upload size limits of downstream cloud services.
     * Returns the bridge file path, or null if afconvert isn't available/fails
     * (caller falls back to sending the original file).
     */
    private function compressAudioTrack(int $jobId, string $filePath): ?string
    {
        // Recreate validates by file extension in the multipart filename and
        // only allows video containers (mp4/mov/mkv/webm/avi) — .m4a is rejected
        // even though the bytes are a valid mp4-family container.
        $bridgePath = STORAGE_PATH . "/uploads/bridge_{$jobId}_track.mp4";
        $out        = [];
        $code       = 0;
        exec(
            'afconvert -f m4af -d aac -s 2 -b 64000 '
            . escapeshellarg($filePath) . ' ' . escapeshellarg($bridgePath) . ' 2>&1',
            $out, $code
        );

        if ($code !== 0 || !file_exists($bridgePath)) {
            error_log("[Job {$jobId}] compressAudioTrack failed (code {$code}): " . implode(' ', $out));
            return null;
        }
        return $bridgePath;
    }

    /**
     * Publish a local audio file to a public URL the Model API gateway can fetch.
     *
     * Recreate's extractAudioFromVideo only accepts video containers, so audio
     * is first repacked into an m4a (mp4 container) via afconvert. The result
     * is always an MP3 URL. Returns [url, ext, mimeType, durationSeconds].
     */
    private function publishLocalAudio(int $jobId, string $filePath, string $ext, string $mimeType): array
    {
        if (in_array($ext, SUPPORTED_VIDEO, true)) {
            $res = $this->recreate->extractAudio($filePath, $mimeType);
            return [$res['url'], 'mp3', 'audio/mpeg', $res['duration']];
        }

        $tmpPath = STORAGE_PATH . "/uploads/bridge_{$jobId}.mp4";

        // Files are sometimes mislabeled (an .mp3 holding an mp4 container) —
        // detect the real container by magic bytes, not by extension.
        $head = (string)@file_get_contents($filePath, false, null, 0, 12);
        if (substr($head, 4, 4) === 'ftyp') {
            copy($filePath, $tmpPath);
        } else {
            $out  = [];
            $code = 0;
            exec('afconvert -f m4af -d aac ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tmpPath) . ' 2>&1', $out, $code);
            if ($code !== 0 || !file_exists($tmpPath)) {
                throw new RuntimeException("Не удалось подготовить файл для облака (afconvert code {$code}): " . implode(' ', $out));
            }
        }

        try {
            $res = $this->recreate->extractAudio($tmpPath, 'video/mp4');
        } finally {
            @unlink($tmpPath);
        }

        return [$res['url'], 'mp3', 'audio/mpeg', $res['duration']];
    }

    // -------------------------------------------------------------------------
    // Helpers

    /**
     * Remove words from the beginning of $text that already appear at the end
     * of the previously collected segments (overlap deduplication).
     *
     * Mangisoz returns ~28-second segments that often straddle chunk boundaries.
     * We compare the leading words of the new segment against the trailing words
     * of the accumulated text and strip any common prefix.
     */
    private function removeLeadingDuplicates(array $prevSegments, string $text): string
    {
        if (empty($prevSegments) || $text === '') {
            return $text;
        }

        $recent    = array_slice($prevSegments, -4);
        $prevWords = preg_split('/\s+/u', trim(implode(' ', array_column($recent, 'text'))), -1, PREG_SPLIT_NO_EMPTY);
        $segWords  = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($prevWords) || empty($segWords)) {
            return $text;
        }

        $maxCheck = min(count($prevWords), count($segWords) - 1, 20);

        // Compare words case-insensitively AND without punctuation — engines
        // punctuate the same speech differently on each pass ("қасыңызда." vs
        // "қасыңызда,"), which must not break duplicate detection.
        $norm = fn(string $w): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($w));

        // 1. Prefix match: new segment starts with words already seen
        for ($len = $maxCheck; $len >= 2; $len--) {
            $segPrefix  = array_map($norm, array_slice($segWords, 0, $len));
            $prevSuffix = array_map($norm, array_slice($prevWords, -$len));
            if ($segPrefix === $prevSuffix) {
                return implode(' ', array_slice($segWords, $len));
            }
        }

        // 2. Inline match: Mangisoz sometimes prepends overlap context before the duplicate.
        //    Find the longest suffix of prevWords that appears anywhere inside segWords,
        //    then strip everything up to and including that match.
        $segLower = array_map($norm, $segWords);
        for ($len = min($maxCheck, 10); $len >= 3; $len--) {
            $prevSuffix = array_map($norm, array_slice($prevWords, -$len));
            $total      = count($segLower);
            for ($i = 0; $i <= $total - $len; $i++) {
                if (array_slice($segLower, $i, $len) === $prevSuffix) {
                    $remainder = array_slice($segWords, $i + $len);
                    return implode(' ', $remainder);
                }
            }
        }

        return $text;
    }

    /**
     * Merge consecutive segments from the same speaker with no gap between them.
     * Produces cleaner output when chunking splits a sentence across two tiny segments.
     */
    private function mergeAdjacentSegments(array $segments): array
    {
        if (count($segments) <= 1) return $segments;

        $merged  = [];
        $current = $segments[0];

        for ($i = 1; $i < count($segments); $i++) {
            $next       = $segments[$i];
            $currentEnd = $this->tsToSecs($current['end']);
            $nextStart  = $this->tsToSecs($next['start']);
            $mergedDur  = $this->tsToSecs($next['end']) - $this->tsToSecs($current['start']);

            // Merge adjacent same-speaker segments into larger thought-sized
            // blocks, capped at ~30 s so segments stay navigable.
            $isAdjacent = ($nextStart - $currentEnd) <= 1.0;

            if ($current['speaker'] === $next['speaker'] && $isAdjacent && $mergedDur <= 30.0) {
                $current['end']  = $next['end'];
                $current['text'] = trim($current['text'] . ' ' . $next['text']);
            } else {
                $merged[] = $current;
                $current  = $next;
            }
        }
        $merged[] = $current;
        return $merged;
    }

    /**
     * Estimate audio duration in seconds from file size and typical bitrate.
     * Used when metadata duration is unavailable (web uploads).
     */
    private function estimateDuration(string $filePath, string $ext): int
    {
        $size = @filesize($filePath);
        if (!$size) return 0;

        // Typical bytes-per-second for each format at common extraction bitrates
        $bps = match ($ext) {
            'mp3'          => 16000,  // 128 kbps
            'm4a', 'aac'   => 12000,  // 96 kbps
            'opus'         => 8000,   // 64 kbps
            'ogg', 'oga'   => 10000,  // 80 kbps
            'wav'          => 176400, // 44100 Hz * 2 ch * 2 bytes
            default        => 16000,
        };

        return (int)($size / $bps);
    }

    private function tsToSecs(string $ts): float
    {
        $parts = explode(':', $ts);
        return (float)($parts[0] ?? 0) * 3600
             + (float)($parts[1] ?? 0) * 60
             + (float)($parts[2] ?? 0);
    }

    private function secsToTs(float $s): string
    {
        $h  = (int)($s / 3600);
        $m  = (int)(fmod($s, 3600) / 60);
        $sc = (int)fmod($s, 60);
        $ms = (int)round(fmod($s, 1) * 1000);
        return sprintf('%02d:%02d:%02d.%03d', $h, $m, $sc, $ms);
    }

    private function saveResults(int $jobId, array $segments, string $language, ?string $summary = null): array
    {
        $base     = STORAGE_PATH . "/results/job_{$jobId}";
        $txtPath  = $base . '.txt';
        $jsonPath = $base . '.json';

        $txt = $this->formatTxt($segments);
        if ($summary !== null && $summary !== '') {
            $txt = "=== РЕЗЮМЕ ===\n\n{$summary}\n\n=== ТРАНСКРИПТ ===\n\n{$txt}";
        }

        file_put_contents($txtPath, $txt);
        file_put_contents($jsonPath, json_encode(
            ['summary' => $summary, 'segments' => $segments],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $pdfPath = $this->pdf->generate($jobId, $segments, $language);

        return [$txtPath, $jsonPath, $pdfPath];
    }

    private function formatTxt(array $segments): string
    {
        $lines = [];
        foreach ($segments as $seg) {
            $start   = $this->fmtTime($seg['start']   ?? '00:00:00.000');
            $end     = $this->fmtTime($seg['end']     ?? '00:00:00.000');
            $speaker = ($seg['speaker'] ?? 'Speaker 1') . $this->genderLabel($seg['gender'] ?? null);
            $text    = trim($seg['text'] ?? '');
            $lines[] = "[{$start} - {$end}] {$speaker}: {$text}";
        }
        return implode("\n\n", $lines);
    }

    private function fmtTime(string $ts): string
    {
        return substr($ts, 0, 8);
    }

    private function genderLabel(?string $gender): string
    {
        return match (strtoupper($gender ?? '')) {
            'M', 'MALE'   => ' (М)',
            'F', 'FEMALE' => ' (Ж)',
            default       => '',
        };
    }

    private function pluralPart(int $n): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) return 'часть';
        if (in_array($n % 10, [2, 3, 4]) && !in_array($n % 100, [12, 13, 14])) return 'части';
        return 'частей';
    }

    /**
     * Publish a local audio file to a public URL purely for diarization.
     * Best-effort: returns null on any failure instead of throwing — losing
     * speaker labels must never break the actual transcription.
     */
    private function publishForDiarization(string $localPath): ?string
    {
        // Recreate's extractAudioFromVideo validates by file EXTENSION and
        // only accepts video containers (mp4/mov/...) — a .m4a/.wav chunk is
        // rejected outright even though the bytes may be a valid container.
        $bridgePath = $localPath;
        $isTemp     = false;
        if (!str_ends_with(strtolower($localPath), '.mp4')) {
            $bridgePath = STORAGE_PATH . '/uploads/diarize_bridge_' . uniqid() . '.mp4';
            if (!@copy($localPath, $bridgePath)) {
                return null;
            }
            $isTemp = true;
        }

        try {
            $res = $this->recreate->extractAudio($bridgePath, 'video/mp4');
            return $res['url'];
        } catch (Throwable $e) {
            error_log("[Job {$this->currentJobId}] diarization publish failed: " . $e->getMessage());
            return null;
        } finally {
            if ($isTemp) @unlink($bridgePath);
        }
    }

    /**
     * Mangisoz's STT output carries no speaker information at all — this asks
     * Gemini to identify speaker turns separately and never lets a failure
     * here break transcription: on any error/timeout/bad JSON it returns [],
     * and callers fall back to the previous single-speaker behavior.
     */
    private function diarizeSafely(string $fileUrl, string $mimeType): array
    {
        try {
            return $this->model->diarize($fileUrl, $mimeType);
        } catch (Throwable $e) {
            error_log("[Job {$this->currentJobId}] diarization skipped: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Stamp a "speaker" label onto each transcribed segment by finding which
     * diarization turn overlaps it the most (in time). Segments are matched
     * independently of exact wording — diarization only knows "who talked
     * when", not what was said.
     */
    private function assignSpeakersByOverlap(array $segments, array $turns): array
    {
        if (empty($turns)) {
            return $segments; // no diarization data — leave existing speaker labels as-is
        }

        $turnRanges = array_map(fn(array $t) => [
            'start'   => $this->tsToSecs($t['start'] ?? '00:00:00.000'),
            'end'     => $this->tsToSecs($t['end']   ?? '00:00:00.000'),
            'speaker' => $t['speaker'] ?? 'Speaker 1',
            'gender'  => $t['gender'] ?? null,
        ], $turns);

        foreach ($segments as &$seg) {
            $segStart = $this->tsToSecs($seg['start'] ?? '00:00:00.000');
            $segEnd   = $this->tsToSecs($seg['end']   ?? '00:00:00.000');

            $bestSpeaker = null;
            $bestGender  = null;
            $bestOverlap = 0.0;
            foreach ($turnRanges as $turn) {
                $overlap = min($segEnd, $turn['end']) - max($segStart, $turn['start']);
                if ($overlap > $bestOverlap) {
                    $bestOverlap = $overlap;
                    $bestSpeaker = $turn['speaker'];
                    $bestGender  = $turn['gender'];
                }
            }

            if ($bestSpeaker !== null) {
                $seg['speaker'] = $bestSpeaker;
                $seg['gender']  = $bestGender;
            }
        }

        return $segments;
    }

    private function mimeType(string $ext): string
    {
        return match ($ext) {
            'mp3'                => 'audio/mpeg',
            'wav'                => 'audio/wav',
            'm4a'                => 'audio/mp4',
            'aac'                => 'audio/aac',
            'ogg', 'oga', 'opus' => 'audio/ogg',
            'mp4'                => 'video/mp4',
            'mov'                => 'video/quicktime',
            default              => 'audio/mpeg',
        };
    }

    private function status(string $text): void
    {
        $this->db->updateJob($this->currentJobId, ['status_message' => $text]);
    }

    private function setProgress(int $percent): void
    {
        if ($this->db->shouldCancel($this->currentJobId)) {
            throw new RuntimeException('Job was cancelled by user');
        }
        $this->db->setProgress($this->currentJobId, min(100, max(0, $percent)));
    }
}
