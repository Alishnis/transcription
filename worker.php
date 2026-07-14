#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ModelApi.php';
require_once __DIR__ . '/src/MangisozApi.php';
require_once __DIR__ . '/src/RecreateApi.php';
require_once __DIR__ . '/src/PdfGenerator.php';
require_once __DIR__ . '/src/Transcriber.php';

$jobId = (int)($argv[1] ?? 0);
if (!$jobId) {
    fwrite(STDERR, "Usage: php worker.php <job_id>\n");
    exit(1);
}

$db          = new Database(DB_PATH);
$model       = new ModelApi(MODEL_API_URL);
$mangisoz    = new MangisozApi(MANGISOZ_API_KEY);
$recreate    = new RecreateApi();
$transcriber = new Transcriber($model, $db, $mangisoz, $recreate);

$transcriber->process($jobId);
