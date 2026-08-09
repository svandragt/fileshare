<?php
/**
 * Smoke test. Run with: php tests/smoke.php
 *
 * Boots the app on a throwaway copy of the tree so the real .env, uploads/
 * and data/ are never touched, then exercises the cron endpoint over HTTP.
 */
declare(strict_types=1);

$failures = 0;

function check(string $what, mixed $expected, mixed $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "  ok   $what\n";
        return;
    }
    $failures++;
    printf("  FAIL %s\n       expected: %s\n       actual:   %s\n", $what, var_export($expected, true), var_export($actual, true));
}

// --- Throwaway copy of the app ---

$root   = dirname(__DIR__);
$tmp    = sys_get_temp_dir() . '/fileshare-smoke-' . getmypid();
$secret = 'test-secret-' . bin2hex(random_bytes(8));

register_shutdown_function(static function () use ($tmp) {
    exec('rm -rf ' . escapeshellarg($tmp));
});

mkdir($tmp . '/data', 0777, true);
mkdir($tmp . '/uploads', 0777, true);
exec(sprintf('cp -r %s %s', escapeshellarg($root . '/src'), escapeshellarg($tmp . '/src')));
file_put_contents($tmp . '/.env', "USERNAME=test\nPASSWORD=x\nCRON_SECRET=$secret\n");

// An already-expired file, so cron has something real to delete. Every cron
// request consumes this, so re-seed before any assertion that depends on it.
$seed = static function () use ($tmp) {
    file_put_contents($tmp . '/uploads/expired.txt', 'bye');
    file_put_contents($tmp . '/uploads/kept.txt', 'stay');
    file_put_contents($tmp . '/data/files.json', json_encode([
        ['path' => 'expired.txt', 'private' => false, 'expires' => time() - 3600, 'uploaded' => time()],
        ['path' => 'kept.txt',    'private' => false, 'expires' => null,          'uploaded' => time()],
    ]));
};
$seed();

// --- Boot the built-in server on a free port ---

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int)explode(':', stream_socket_get_name($probe, false))[1];
fclose($probe);

$server = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($tmp . '/src'), escapeshellarg($tmp . '/src/router.php')),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
register_shutdown_function(static function () use ($server) {
    proc_terminate($server);
});

for ($i = 0; $i < 100; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($probe) { fclose($probe); break; }
    usleep(50_000);
}

/** @return array{0:int,1:string} status code and body */
function get(string $url, array $headers = []): array
{
    $ctx  = stream_context_create(['http' => [
        'header'        => $headers,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx) ?: '';
    preg_match('{ (\d{3}) }', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body];
}

$base = "http://127.0.0.1:$port";

// --- Cron authentication ---

echo "cron auth\n";
check('header with the right secret is accepted', 200, get("$base/cron", ["X-Cron-Secret: $secret"])[0]);
check('query parameter still accepted (legacy callers)', 200, get("$base/cron?secret=$secret")[0]);
check('wrong header secret is rejected', 403, get("$base/cron", ['X-Cron-Secret: wrong'])[0]);
check('wrong query secret is rejected', 403, get("$base/cron?secret=wrong")[0]);
check('no secret at all is rejected', 403, get("$base/cron")[0]);

// --- Cron actually expires files ---

echo "cron cleanup\n";
$seed();
[, $body] = get("$base/cron?secret=$secret");
check('reports one removal', true, str_contains($body, 'Removed 1 expired file(s).'));
check('expired file is deleted', false, file_exists($tmp . '/uploads/expired.txt'));
check('unexpired file is kept', true, file_exists($tmp . '/uploads/kept.txt'));
check('expired entry dropped from metadata', ['kept.txt'], array_column(
    json_decode(file_get_contents($tmp . '/data/files.json'), true),
    'path'
));

// --- Secrets must not leak into a rejection response ---

echo "no secret disclosure\n";
[, $forbidden] = get("$base/cron", ['X-Cron-Secret: wrong']);
check('403 body does not echo the real secret', false, str_contains($forbidden, $secret));

echo $failures === 0 ? "\nPASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
