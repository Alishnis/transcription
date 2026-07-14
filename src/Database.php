<?php
declare(strict_types=1);

class Database
{
    public PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id             INTEGER NOT NULL,
                user_id             INTEGER NOT NULL,
                status              TEXT    NOT NULL DEFAULT 'uploaded',
                language            TEXT    NOT NULL DEFAULT 'auto',
                original_file_path  TEXT,
                telegram_file_path  TEXT,
                duration            INTEGER NOT NULL DEFAULT 0,
                result_json_path    TEXT,
                result_txt_path     TEXT,
                error_message       TEXT,
                created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS user_states (
                user_id        INTEGER PRIMARY KEY,
                state          TEXT    NOT NULL DEFAULT 'idle',
                pending_job_id INTEGER,
                updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Add columns that may be missing in existing databases
        $existing = array_column(
            $this->pdo->query("PRAGMA table_info(jobs)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        if (!in_array('telegram_file_path', $existing, true)) {
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN telegram_file_path TEXT");
        }
        if (!in_array('duration', $existing, true)) {
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN duration INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('status_message', $existing, true)) {
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN status_message TEXT");
        }
        if (!in_array('result_pdf_path', $existing, true)) {
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN result_pdf_path TEXT");
        }
        if (!in_array('summary', $existing, true)) {
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN summary TEXT");
        }
        if (!in_array('progress', $existing, true)) {
            // Progress percentage (0-100)
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN progress INTEGER DEFAULT 0");
        }
        if (!in_array('cancel_requested', $existing, true)) {
            // Flag to signal worker to cancel
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN cancel_requested BOOLEAN DEFAULT 0");
        }
        if (!in_array('mode', $existing, true)) {
            // Processing mode: 'text' (точный текст), 'speakers' (по спикерам), 'summary' (краткое содержание)
            $this->pdo->exec("ALTER TABLE jobs ADD COLUMN mode TEXT DEFAULT 'text'");
        }
    }

    public function createJob(int $chatId, int $userId, string $filePath, string $telegramFilePath = '', int $duration = 0): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO jobs (chat_id, user_id, original_file_path, telegram_file_path, duration) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$chatId, $userId, $filePath, $telegramFilePath, $duration]);
        return (int) $this->pdo->lastInsertId();
    }

    public function getJob(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getUserJobs(int $userId, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, status, language, created_at FROM jobs WHERE user_id = ? ORDER BY id DESC LIMIT ?"
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateJob(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        $stmt = $this->pdo->prepare("UPDATE jobs SET {$sets} WHERE id = ?");
        $stmt->execute([...array_values($data), $id]);
    }

    public function getUserState(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM user_states WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC)
            ?: ['user_id' => $userId, 'state' => 'idle', 'pending_job_id' => null];
    }

    public function setUserState(int $userId, string $state, ?int $pendingJobId = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO user_states (user_id, state, pending_job_id, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(user_id) DO UPDATE SET
                state          = excluded.state,
                pending_job_id = excluded.pending_job_id,
                updated_at     = excluded.updated_at
        ");
        $stmt->execute([$userId, $state, $pendingJobId]);
    }

    public function setProgress(int $jobId, int $progress): void
    {
        $stmt = $this->pdo->prepare("UPDATE jobs SET progress = ? WHERE id = ?");
        $stmt->execute([$progress, $jobId]);
    }

    public function requestCancel(int $jobId): void
    {
        $stmt = $this->pdo->prepare("UPDATE jobs SET cancel_requested = 1 WHERE id = ?");
        $stmt->execute([$jobId]);
    }

    public function shouldCancel(int $jobId): bool
    {
        $stmt = $this->pdo->prepare("SELECT cancel_requested FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (bool)($result['cancel_requested'] ?? false);
    }
}
