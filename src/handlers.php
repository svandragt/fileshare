<?php
declare(strict_types=1);

// --- Auth ---

function isLoggedIn(): bool
{
    return !empty($_SESSION['logged_in']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /');
        exit;
    }
}

function verifyCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// --- Route handlers ---

function handleDashboard(): void
{
    if (!isLoggedIn()) { renderLogin(); return; }
    $meta = loadMeta();
    releaseMetaLock();
    usort($meta, fn($a, $b) => $b['uploaded'] <=> $a['uploaded']);
    renderDashboard($meta);
}

function handleLogin(): void
{
    verifyCsrf();
    usleep(300_000);
    if (($_POST['username'] ?? '') === APP_USERNAME && password_verify($_POST['password'] ?? '', APP_PASSWORD)) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        redirect('/');
    }
    renderLogin('Invalid credentials.');
}

function handleLogout(): void
{
    session_unset();
    session_destroy();
    clearSessionCookie();
    redirect('/');
}

/**
 * Moves an uploaded file into UPLOADS_DIR/{folder}, auto-renaming on
 * collision. Shared by the session-based /upload and token-based
 * /api/upload handlers, which differ only in auth and response format.
 *
 * @return array{ok: bool, path?: string, error?: string}
 */
function saveUploadedFile(string $folderInput, string $originalFilename, string $tmpName): array
{
    $folder   = sanitizeFolder($folderInput);
    $filename = basename($originalFilename);

    $targetDir = UPLOADS_DIR . ($folder !== '' ? '/' . $folder : '');

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $targetDir));
        }
    }

    $uploadsReal   = realpath(UPLOADS_DIR);
    $targetDirReal = realpath($targetDir);
    if ($targetDirReal === false || !str_starts_with($targetDirReal, $uploadsReal)) {
        return ['ok' => false, 'error' => 'Invalid folder path.'];
    }

    $filename = autoRename($targetDir, $filename);

    if (!move_uploaded_file($tmpName, $targetDir . '/' . $filename)) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    return ['ok' => true, 'path' => ($folder !== '' ? $folder . '/' : '') . $filename];
}

/**
 * The single verdict on this request's upload, folding the app's own size
 * guard into PHP's UPLOAD_ERR_* codes so both handlers agree on the limit.
 */
function uploadError(): int
{
    $error = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;

    // A body over post_max_size arrives with $_FILES empty and no error set,
    // which otherwise reads as "no file received".
    if ($error === UPLOAD_ERR_NO_FILE && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > phpIniBytes('post_max_size')) {
        return UPLOAD_ERR_INI_SIZE;
    }

    if ($error === UPLOAD_ERR_OK && $_FILES['file']['size'] > effectiveMaxUploadBytes()) {
        return UPLOAD_ERR_FORM_SIZE;
    }

    return $error;
}

function uploadErrorMessage(int $uploadError): string
{
    return match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (max ' . formatBytes(effectiveMaxUploadBytes()) . ').',
        UPLOAD_ERR_NO_FILE                        => 'No file received.',
        default                                   => 'Upload error.',
    };
}

function handleUpload(): void
{
    requireLogin();

    // Before the CSRF check: a body over post_max_size empties $_POST too, so
    // the token is missing and "Invalid CSRF token." would mask the real cause.
    $uploadError = uploadError();
    if ($uploadError !== UPLOAD_ERR_OK) {
        redirect('/', uploadErrorMessage($uploadError));
    }

    verifyCsrf();

    $result = saveUploadedFile($_POST['folder'] ?? '', $_FILES['file']['name'], $_FILES['file']['tmp_name']);
    if (!$result['ok']) {
        redirect('/', $result['error']);
    }

    $meta   = loadMeta();
    $meta[] = [
        'path'     => $result['path'],
        'private'  => !empty($_POST['private']),
        'expires'  => expiryTimestamp($_POST['expiry'] ?? 'never'),
        'uploaded' => time(),
    ];
    saveMeta($meta);

    redirect('/', 'File uploaded successfully.');
}

function handleApiUpload(): void
{
    header('Content-Type: application/json');

    $token = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
        $token = $m[1];
    }
    if (API_UPLOAD_SECRET === '' || !hash_equals(API_UPLOAD_SECRET, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden.']);
        exit;
    }

    $uploadError = uploadError();
    if ($uploadError !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => uploadErrorMessage($uploadError)]
            + (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? ['max_bytes' => effectiveMaxUploadBytes()] : []));
        exit;
    }

    $result = saveUploadedFile($_POST['folder'] ?? '', $_FILES['file']['name'], $_FILES['file']['tmp_name']);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['error' => $result['error']]);
        exit;
    }

    $meta   = loadMeta();
    $meta[] = [
        'path'     => $result['path'],
        'private'  => !empty($_POST['private']),
        'expires'  => expiryTimestamp($_POST['expiry'] ?? 'never'),
        'uploaded' => time(),
    ];
    saveMeta($meta);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    echo json_encode([
        'path' => $result['path'],
        'url'  => $scheme . '://' . $_SERVER['HTTP_HOST'] . '/download/' . $result['path'],
    ]);
    exit;
}

function resolveServableFile(string $filePath): string
{
    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    releaseMetaLock();
    $idx      = findIndex($meta, $filePath);

    if ($idx === false) { http_response_code(404); die('File not found.'); }

    $entry = $meta[$idx];

    if ($entry['expires'] !== null && $entry['expires'] < time()) {
        http_response_code(410);
        die('This file has expired.');
    }

    if ($entry['private'] && !isLoggedIn()) {
        http_response_code(403);
        die('This file is private.');
    }

    $uploadsReal = realpath(UPLOADS_DIR);
    $fullPath    = realpath(UPLOADS_DIR . '/' . $filePath);

    if ($fullPath === false || !str_starts_with($fullPath, $uploadsReal) || !is_file($fullPath)) {
        http_response_code(404);
        die('File not found.');
    }

    return $fullPath;
}

function isHtmlFile(string $path): bool
{
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['html', 'htm'], true);
}

function handleDownload(string $filePath): void
{
    $fullPath = resolveServableFile($filePath);

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . (mime_content_type($fullPath) ?: 'application/octet-stream'));
    $safeName = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '_', basename($fullPath));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

function handleView(string $filePath): void
{
    $fullPath = resolveServableFile($filePath);

    if (!isHtmlFile($fullPath)) {
        http_response_code(404);
        die('File not found.');
    }

    // Sandbox without allow-same-origin: page runs in an opaque origin,
    // so uploaded HTML cannot read the session cookie or call the app as us.
    header('Content-Security-Policy: sandbox allow-scripts');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

/**
 * Index of $filePath in $meta, or bail out. loadMeta() holds LOCK_EX and only
 * saveMeta() drops it, so a miss has to release the lock itself — and say so,
 * otherwise a no-op looks exactly like a success.
 */
function requireEntry(array $meta, string $filePath): int
{
    $idx = findIndex($meta, $filePath);
    if ($idx === false) {
        releaseMetaLock();
        redirect('/', 'File not found.');
    }
    return $idx;
}

function handleDelete(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = requireEntry($meta, $filePath);

    $uploadsReal = realpath(UPLOADS_DIR);
    $fullPath    = realpath(UPLOADS_DIR . '/' . $filePath);
    if ($fullPath !== false && str_starts_with($fullPath, $uploadsReal) && is_file($fullPath)) {
        unlink($fullPath);
    }
    array_splice($meta, $idx, 1);
    saveMeta($meta);

    redirect('/');
}

function handleToggle(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = requireEntry($meta, $filePath);

    $meta[$idx]['private'] = !$meta[$idx]['private'];
    saveMeta($meta);

    redirect('/');
}

function handleExpiry(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    // "Keep" — the select cannot preselect the stored expiry (it is an absolute
    // timestamp matching no preset), so an untouched dropdown means no change.
    if (($_POST['expiry'] ?? '') === '') {
        redirect('/');
    }

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = requireEntry($meta, $filePath);

    $meta[$idx]['expires'] = expiryTimestamp($_POST['expiry']);
    saveMeta($meta);

    redirect('/');
}

function handleCron(): void
{
    // Header is preferred: query strings end up in access logs and crontabs.
    $given = $_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['secret'] ?? '';

    if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, $given)) {
        http_response_code(403);
        die('Forbidden.');
    }

    $meta    = loadMeta();
    $now     = time();
    $removed = 0;

    foreach ($meta as $entry) {
        if ($entry['expires'] !== null && $entry['expires'] < $now) {
            $uploadsReal = realpath(UPLOADS_DIR);
            $fullPath    = realpath(UPLOADS_DIR . '/' . $entry['path']);
            if ($fullPath !== false && str_starts_with($fullPath, $uploadsReal) && is_file($fullPath)) {
                unlink($fullPath);
            }
            $removed++;
        }
    }

    $meta = array_filter($meta, fn($e) => $e['expires'] === null || $e['expires'] >= $now);
    saveMeta($meta);

    header('Content-Type: text/plain');
    echo "Removed $removed expired file(s).\n";
    exit;
}

function notFound(): void
{
    http_response_code(404);
    echo '404 Not Found';
}

// --- Views ---

function renderLogin(?string $error = null): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    include __DIR__ . '/views/login.php';
}

function renderDashboard(array $meta): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    // Group by folder (dirname), root files keyed as ''
    $folders = [];
    foreach ($meta as $entry) {
        $dir = dirname($entry['path']);
        $folders[$dir === '.' ? '' : $dir][] = $entry;
    }
    ksort($folders);

    include __DIR__ . '/views/dashboard.php';
}
