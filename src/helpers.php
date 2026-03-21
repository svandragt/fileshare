<?php
declare(strict_types=1);

// --- Environment ---

function loadEnv(): array
{
    if (!file_exists(ENV_FILE)) {
        die('Missing .env file — copy .env.example to .env and set credentials.');
    }
    $env = [];
    foreach (file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $env[$k] = $v;
    }
    return $env;
}

// --- Metadata ---

function metaLockHandle(): mixed
{
    static $lock = null;
    if ($lock === null) {
        $lock = fopen(LOCK_FILE, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open metadata lock file: ' . LOCK_FILE);
        }
    }
    return $lock;
}

function loadMeta(): array
{
    flock(metaLockHandle(), LOCK_EX);
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?: [];
}

function saveMeta(array $meta): void
{
    file_put_contents(DATA_FILE, json_encode(array_values($meta), JSON_PRETTY_PRINT));
    flock(metaLockHandle(), LOCK_UN);
}

function releaseMetaLock(): void
{
    flock(metaLockHandle(), LOCK_UN);
}

function findIndex(array $meta, string $path): int|false
{
    foreach ($meta as $i => $entry) {
        if ($entry['path'] === $path) return $i;
    }
    return false;
}

// --- Path helpers ---

function sanitizeFolder(string $folder): string
{
    $folder = str_replace('\\', '/', $folder);
    $folder = preg_replace('#\.\.+#', '', $folder);
    $folder = preg_replace('#[^a-zA-Z0-9/_.\- ]#', '', $folder);
    return trim($folder, '/');
}

function autoRename(string $dir, string $filename): string
{
    if (!file_exists($dir . '/' . $filename)) return $filename;
    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    for ($i = 1; ; $i++) {
        $candidate = $ext ? "$base($i).$ext" : "$base($i)";
        if (!file_exists($dir . '/' . $candidate)) return $candidate;
    }
}

// --- Expiry ---

function expiryTimestamp(string $value): ?int
{
    return match ($value) {
        '1h'  => time() + 3_600,
        '6h'  => time() + 21_600,
        '24h' => time() + 86_400,
        '3d'  => time() + 86_400 * 3,
        '7d'  => time() + 86_400 * 7,
        '30d' => time() + 86_400 * 30,
        default => null,
    };
}

function formatExpiry(?int $ts): string
{
    if ($ts === null) return '—';
    $diff = $ts - time();
    if ($diff <= 0) return 'Expired';
    if ($diff < 3_600)  return round($diff / 60) . 'm';
    if ($diff < 86_400) return round($diff / 3_600) . 'h';
    return round($diff / 86_400) . 'd';
}

// --- Upload helpers ---

function phpIniBytes(string $key): int
{
    $val = trim((string)ini_get($key));
    if ($val === '') return PHP_INT_MAX;
    $last = strtolower($val[-1]);
    $n    = (int)$val;
    return match ($last) {
        'g'     => $n * 1024 * 1024 * 1024,
        'm'     => $n * 1024 * 1024,
        'k'     => $n * 1024,
        default => $n,
    };
}

function effectiveMaxUploadBytes(): int
{
    return min(MAX_UPLOAD_BYTES, phpIniBytes('upload_max_filesize'), phpIniBytes('post_max_size'));
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
    if ($bytes >= 1024 * 1024)        return round($bytes / (1024 * 1024), 1) . ' MB';
    if ($bytes >= 1024)               return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// --- Output helpers ---

function clearSessionCookie(): void
{
    setcookie(session_name(), '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

function redirect(string $to, ?string $flash = null): never
{
    if ($flash !== null) $_SESSION['flash'] = $flash;
    header('Location: ' . $to);
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5);
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}
