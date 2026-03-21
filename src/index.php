<?php
declare(strict_types=1);

use JetBrains\PhpStorm\NoReturn;

define('ROOT',        dirname(__DIR__));
define('UPLOADS_DIR', ROOT . '/uploads');
define('DATA_FILE',   ROOT . '/data/files.json');
define('ENV_FILE',    ROOT . '/.env');

// --- Bootstrap ---

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

$env = loadEnv();
define('APP_USERNAME', $env['USERNAME'] ?? '');
define('APP_PASSWORD', $env['PASSWORD'] ?? '');

session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// --- Metadata ---

function loadMeta(): array
{
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?: [];
}

function saveMeta(array $meta): void
{
    file_put_contents(DATA_FILE, json_encode(array_values($meta), JSON_PRETTY_PRINT));
}

function findIndex(array $meta, string $path): int|false
{
    foreach ($meta as $i => $entry) {
        if ($entry['path'] === $path) return $i;
    }
    return false;
}

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

// --- Misc helpers ---

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

// --- Router ---

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    $path === '/'                                                       => handleDashboard(),
    $path === '/login'  && $method === 'POST'                          => handleLogin(),
    $path === '/logout'                                                 => handleLogout(),
    $path === '/upload' && $method === 'POST'                          => handleUpload(),
    str_starts_with($path, '/download/')                               => handleDownload(substr($path, 10)),
    str_starts_with($path, '/delete/')  && $method === 'POST'          => handleDelete(substr($path, 8)),
    str_starts_with($path, '/toggle/')  && $method === 'POST'          => handleToggle(substr($path, 8)),
    str_starts_with($path, '/expiry/')  && $method === 'POST'          => handleExpiry(substr($path, 8)),
    $path === '/cron'                                                   => handleCron(),
    default                                                             => notFound(),
};

// --- Handlers ---

function handleDashboard(): void
{
    if (!isLoggedIn()) { renderLogin(); return; }
    $meta = loadMeta();
    usort($meta, fn($a, $b) => $b['uploaded'] <=> $a['uploaded']);
    renderDashboard($meta);
}

function handleLogin(): void
{
    verifyCsrf();
    if (($_POST['username'] ?? '') === APP_USERNAME && ($_POST['password'] ?? '') === APP_PASSWORD) {
        $_SESSION['logged_in'] = true;
        redirect('/');
    }
    renderLogin('Invalid credentials.');
}

function handleLogout(): void
{
    session_destroy();
    redirect('/');
}

function handleUpload(): void
{
    requireLogin();
    verifyCsrf();

    if (empty($_FILES['file']['name'])) {
        redirect('/', 'No file selected.');
    }

    $folder   = sanitizeFolder($_POST['folder'] ?? '');
    $private  = !empty($_POST['private']);
    $expiry   = $_POST['expiry'] ?? 'never';
    $filename = basename($_FILES['file']['name']);

    $targetDir = UPLOADS_DIR . ($folder !== '' ? '/' . $folder : '');

    if (!is_dir($targetDir)) {
		if ( ! mkdir( $targetDir, 0755, true ) && ! is_dir( $targetDir ) ) {
			throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $targetDir ) );
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

#[NoReturn]
function handleDownload(string $filePath): void
{
    $filePath = ltrim($filePath, '/');
    $meta     = loadMeta();
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

    header('Content-Type: ' . (mime_content_type($fullPath) ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes(basename($fullPath)) . '"');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
}

function handleDelete(string $filePath): void
{
    requireLogin();
    verifyCsrf();

    $filePath    = ltrim($filePath, '/');
    $meta        = loadMeta();
    $idx         = findIndex($meta, $filePath);

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
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fileshare — Login</title>
    <link rel="stylesheet" href="/simple.min.css">
    <style>
        body { max-width: 28rem; }
        form { display: flex; flex-direction: column; gap: .75rem; }
    </style>
</head>
<body>
    <header><h1>Fileshare</h1></header>
    <main>
        <?php if ($error): ?><p><strong><?= h($error) ?></strong></p><?php endif; ?>
        <?php if ($flash): ?><p><?= h($flash) ?></p><?php endif; ?>
        <form method="post" action="/login">
            <?= csrfField() ?>
            <label>Username
                <input type="text" name="username" required autofocus autocomplete="username">
            </label>
            <label>Password
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button type="submit">Log in</button>
        </form>
    </main>
</body>
</html>
    <?php
}

function renderDashboard(array $meta): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fileshare</title>
    <link rel="stylesheet" href="/simple.min.css">
    <style>
        .file-list { list-style: none; padding: 0; margin: 0; }
        .file-item {
            padding: .75rem 0;
            border-bottom: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }
        .file-name { word-break: break-all; }
        .file-info { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
        .badge {
            font-size: .75rem;
            padding: .1rem .45rem;
            border-radius: 3px;
            background: var(--accent-bg);
            color: var(--text-light);
            white-space: nowrap;
        }
        .badge-private { background: #fce8e8; color: #b00; }
        .file-actions { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
        .file-actions form { margin: 0; }
        .file-actions button { font-size: .85rem; padding: .25rem .6rem; margin: 0; }
        .file-actions select { font-size: .85rem; padding: .25rem; }
        .expiry-form { display: flex; gap: .3rem; align-items: center; }
        .upload-form { display: flex; flex-direction: column; gap: .75rem; }
        .upload-options { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; }
    </style>
</head>
<body>
    <header>
        <h1>Fileshare</h1>
        <nav><a href="/logout">Log out</a></nav>
    </header>
    <main>
        <?php if ($flash): ?><p><strong><?= h($flash) ?></strong></p><?php endif; ?>

        <section>
            <h2>Upload</h2>
            <form class="upload-form" method="post" action="/upload" enctype="multipart/form-data">
                <?= csrfField() ?>
                <label>File
                    <input type="file" name="file" required>
                </label>
                <label>Folder <small>(optional)</small>
                    <input type="text" name="folder" placeholder="e.g. projects/2024">
                </label>
                <div class="upload-options">
                    <label><input type="checkbox" name="private"> Private</label>
                    <label>Expires
                        <select name="expiry">
                            <option value="never">Never</option>
                            <option value="1h">1 hour</option>
                            <option value="6h">6 hours</option>
                            <option value="24h">24 hours</option>
                            <option value="3d">3 days</option>
                            <option value="7d">7 days</option>
                            <option value="30d">30 days</option>
                        </select>
                    </label>
                </div>
                <div><button type="submit">Upload</button></div>
            </form>
        </section>

        <section>
            <h2>Files</h2>
            <?php if (empty($meta)): ?>
                <p>No files yet.</p>
            <?php else: ?>
            <ul class="file-list">
                <?php foreach ($meta as $entry): ?>
                <li class="file-item">
                    <div class="file-name">
                        <a href="/download/<?= h($entry['path']) ?>"><?= h($entry['path']) ?></a>
                    </div>
                    <div class="file-info">
                        <?php if ($entry['private']): ?>
                            <span class="badge badge-private">private</span>
                        <?php else: ?>
                            <span class="badge">public</span>
                        <?php endif; ?>
                        <span class="badge">expires: <?= h(formatExpiry($entry['expires'])) ?></span>
                    </div>
                    <div class="file-actions">
                        <form method="post" action="/toggle/<?= h($entry['path']) ?>">
                            <?= csrfField() ?>
                            <button type="submit"><?= $entry['private'] ? 'Make public' : 'Make private' ?></button>
                        </form>
                        <form class="expiry-form" method="post" action="/expiry/<?= h($entry['path']) ?>">
                            <?= csrfField() ?>
                            <select name="expiry">
                                <option value="never">Never</option>
                                <option value="1h">1h</option>
                                <option value="6h">6h</option>
                                <option value="24h">24h</option>
                                <option value="3d">3d</option>
                                <option value="7d">7d</option>
                                <option value="30d">30d</option>
                            </select>
                            <button type="submit">Set expiry</button>
                        </form>
                        <form method="post" action="/delete/<?= h($entry['path']) ?>"
                              onsubmit="return confirm('Delete <?= h(addslashes($entry['path'])) ?>?')">
                            <?= csrfField() ?>
                            <button type="submit">Delete</button>
                        </form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
    <?php
}
