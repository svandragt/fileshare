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

function handleUpload(): void
{
    requireLogin();
    verifyCsrf();

    $uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        redirect('/', match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large.',
            UPLOAD_ERR_NO_FILE                        => 'No file selected.',
            default                                   => 'Upload error.',
        });
    }

    if ($_FILES['file']['size'] > MAX_UPLOAD_BYTES) {
        redirect('/', 'File too large (max 50 MB).');
    }

    $folder   = sanitizeFolder($_POST['folder'] ?? '');
    $private  = !empty($_POST['private']);
    $expiry   = $_POST['expiry'] ?? 'never';
    $filename = basename($_FILES['file']['name']);

    $targetDir = UPLOADS_DIR . ($folder !== '' ? '/' . $folder : '');

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $targetDir));
        }
    }

    $uploadsReal   = realpath(UPLOADS_DIR);
    $targetDirReal = realpath($targetDir);
    if ($targetDirReal === false || !str_starts_with($targetDirReal, $uploadsReal)) {
        redirect('/', 'Invalid folder path.');
    }

    $filename = autoRename($targetDir, $filename);

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetDir . '/' . $filename)) {
        redirect('/', 'Upload failed.');
    }

    $relativePath = ($folder !== '' ? $folder . '/' : '') . $filename;

    $meta   = loadMeta();
    $meta[] = [
        'path'     => $relativePath,
        'private'  => $private,
        'expires'  => expiryTimestamp($expiry),
        'uploaded' => time(),
    ];
    saveMeta($meta);

    redirect('/', 'File uploaded successfully.');
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

function handleDelete(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = findIndex($meta, $filePath);

    if ($idx !== false) {
        $uploadsReal = realpath(UPLOADS_DIR);
        $fullPath    = realpath(UPLOADS_DIR . '/' . $filePath);
        if ($fullPath !== false && str_starts_with($fullPath, $uploadsReal) && is_file($fullPath)) {
            unlink($fullPath);
        }
        array_splice($meta, $idx, 1);
        saveMeta($meta);
    }

    redirect('/');
}

function handleToggle(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = findIndex($meta, $filePath);

    if ($idx !== false) {
        $meta[$idx]['private'] = !$meta[$idx]['private'];
        saveMeta($meta);
    }

    redirect('/');
}

function handleExpiry(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
    $idx      = findIndex($meta, $filePath);

    if ($idx !== false) {
        $meta[$idx]['expires'] = expiryTimestamp($_POST['expiry'] ?? 'never');
        saveMeta($meta);
    }

    redirect('/');
}

function handleCron(): void
{
    if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, $_GET['secret'] ?? '')) {
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
