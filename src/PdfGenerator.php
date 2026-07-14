<?php
declare(strict_types=1);

/**
 * Generates PDF using tFPDF (UTF-8 capable FPDF fork) + DejaVu Sans.
 * Downloads tFPDF and font from GitHub on first use (~800 KB), then caches.
 */
class PdfGenerator
{
    // tFPDF expects fonts at {fontpath}/unifont/{file}
    // Default fontpath = dirname(tfpdf.php) . '/font/'
    private const BASE   = __DIR__ . '/../vendor/tfpdf';
    private const REMOTE = 'https://raw.githubusercontent.com/setasign/tfpdf/master/';

    private const DOWNLOADS = [
        'tfpdf.php'                    => 'tfpdf.php',
        'font/unifont/DejaVuSans.ttf'  => 'font/unifont/DejaVuSans.ttf',
        'font/unifont/ttfonts.php'     => 'font/unifont/ttfonts.php',
    ];

    public function generate(int $jobId, array $segments, string $language): ?string
    {
        try {
            $this->ensureLib();
            return $this->makePdf($jobId, $segments, $language);
        } catch (Throwable $e) {
            error_log("[PdfGenerator] " . $e->getMessage());
            return null;
        }
    }

    private function ensureLib(): void
    {
        foreach (self::DOWNLOADS as $local => $remote) {
            $dest = self::BASE . '/' . $local;
            if (!file_exists($dest)) {
                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0755, true);
                }
                $data = $this->download(self::REMOTE . $remote);
                file_put_contents($dest, $data);
            }
        }
    }

    private function download(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($data === false || $code !== 200) {
            throw new RuntimeException("Download failed [{$code}] {$url}: {$err}");
        }
        return (string)$data;
    }

    private function makePdf(int $jobId, array $segments, string $language): string
    {
        if (!class_exists('tFPDF')) {
            require_once self::BASE . '/tfpdf.php';
        }
        if (!class_exists('TTFontFile')) {
            require_once self::BASE . '/font/unifont/ttfonts.php';
        }

        $langLabel = match ($language) {
            'ru'    => 'Русский',
            'kz'    => 'Казахский',
            default => 'Авто',
        };
        $date  = date('d.m.Y H:i');
        $count = count($segments);

        // tFPDF defaults fontpath to dirname(tfpdf.php).'/font/' so no FPDF_FONTPATH needed
        $pdf = new tFPDF('P', 'mm', 'A4');
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);

        // Title
        $pdf->SetFont('DejaVu', '', 18);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 10, 'Транскрибация', 0, 1);

        // Meta
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 6, "Язык: {$langLabel}   ·   Сегментов: {$count}   ·   {$date}", 0, 1);

        // Separator line
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Line(20, $pdf->GetY() + 2, 190, $pdf->GetY() + 2);
        $pdf->Ln(6);

        foreach ($segments as $seg) {
            $start   = substr($seg['start']   ?? '00:00:00.000', 0, 8);
            $end     = substr($seg['end']     ?? '00:00:00.000', 0, 8);
            $speaker = ($seg['speaker'] ?? 'Speaker 1') . $this->genderLabel($seg['gender'] ?? null);
            $text    = trim($seg['text']    ?? '');

            if ($text === '') continue;

            // Timestamp + speaker
            $pdf->SetFont('DejaVu', '', 8);
            $pdf->SetTextColor(37, 99, 235);
            $pdf->Cell(0, 5, "[{$start} — {$end}]  {$speaker}", 0, 1);

            // Transcribed text
            $pdf->SetFont('DejaVu', '', 10);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->MultiCell(0, 6, $text);

            $pdf->SetDrawColor(241, 245, 249);
            $pdf->Line(20, $pdf->GetY() + 1, 190, $pdf->GetY() + 1);
            $pdf->Ln(5);
        }

        $pdfPath = STORAGE_PATH . "/results/job_{$jobId}.pdf";
        $pdf->Output('F', $pdfPath);
        return $pdfPath;
    }

    private function genderLabel(?string $gender): string
    {
        return match (strtoupper($gender ?? '')) {
            'M', 'MALE'   => ' (М)',
            'F', 'FEMALE' => ' (Ж)',
            default       => '',
        };
    }
}
