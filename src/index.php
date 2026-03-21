<?php
declare(strict_types=1);

define('ROOT',        dirname(__DIR__));
define('UPLOADS_DIR', ROOT . '/uploads');
define('DATA_FILE',   ROOT . '/data/files.json');
define('ENV_FILE',    ROOT . '/.env');

require __DIR__ . '/helpers.php';
require __DIR__ . '/handlers.php';

// --- Bootstrap ---

$env = loadEnv();
define('APP_USERNAME', $env['USERNAME'] ?? '');
define('APP_PASSWORD', $env['PASSWORD'] ?? '');

session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// --- Router ---

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

match (true) {
    $path === '/'                                              => handleDashboard(),
    $path === '/login'  && $method === 'POST'                 => handleLogin(),
    $path === '/logout'                                       => handleLogout(),
    $path === '/upload' && $method === 'POST'                 => handleUpload(),
    str_starts_with($path, '/download/')                      => handleDownload(substr($path, 10)),
    str_starts_with($path, '/delete/')  && $method === 'POST' => handleDelete(substr($path, 8)),
    str_starts_with($path, '/toggle/')  && $method === 'POST' => handleToggle(substr($path, 8)),
    str_starts_with($path, '/expiry/')  && $method === 'POST' => handleExpiry(substr($path, 8)),
    $path === '/cron'                                         => handleCron(),
    default                                                   => notFound(),
};
