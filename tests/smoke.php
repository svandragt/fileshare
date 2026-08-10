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
$apiSecret = 'api-secret-' . bin2hex(random_bytes(8));
$password  = 'pw-' . bin2hex(random_bytes(8));
$hash      = password_hash($password, PASSWORD_DEFAULT);
file_put_contents($tmp . '/.env', "USERNAME=test\nPASSWORD=$hash\nCRON_SECRET=$secret\nAPI_UPLOAD_SECRET=$apiSecret\nMAX_UPLOAD_MB=50\n");

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

/** @param array<string,string> $ini php.ini overrides for this server */
function boot(string $tmp, array $ini = []): string
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int)explode(':', stream_socket_get_name($probe, false))[1];
    fclose($probe);

    $flags = '';
    foreach ($ini as $k => $v) {
        $flags .= ' -d ' . escapeshellarg("$k=$v");
    }

    $server = proc_open(
        sprintf('exec php%s -S 127.0.0.1:%d -t %s %s', $flags, $port, escapeshellarg($tmp . '/src'), escapeshellarg($tmp . '/src/router.php')),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    register_shutdown_function(static fn() => proc_terminate($server));

    for ($i = 0; $i < 100; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(50_000);
    }

    return "http://127.0.0.1:$port";
}

/** @return array{0:int,1:string} status code and body */
function get(string $url, array $headers = []): array
{
    return request($url, ['header' => $headers]);
}

/** Multipart POST of a single in-memory file. @return array{0:int,1:string} */
function postFile(string $url, string $filename, string $contents, array $headers = []): array
{
    $boundary = '----smoke' . bin2hex(random_bytes(8));
    $body     = "--$boundary\r\n"
        . "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n"
        . "Content-Type: application/octet-stream\r\n\r\n"
        . $contents . "\r\n--$boundary--\r\n";

    return request($url, [
        'method'  => 'POST',
        'header'  => array_merge($headers, ["Content-Type: multipart/form-data; boundary=$boundary"]),
        'content' => $body,
    ]);
}

/** @return array{0:int,1:string,2:string[]} status code, body, response headers */
function request(string $url, array $http): array
{
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => $http + ['ignore_errors' => true, 'follow_location' => 0],
    ])) ?: '';
    $headers = $http_response_header ?? [];
    preg_match('{ (\d{3}) }', $headers[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $body, $headers];
}

/**
 * Logged-in browser: replays the session cookie and the CSRF token, so the
 * dashboard's own forms can be exercised as a user submits them.
 */
final class Browser
{
    private string $cookie = '';
    private string $csrf   = '';

    public function __construct(private string $base) {}

    public function login(string $user, string $password): void
    {
        $this->get('/');
        $this->post('/login', ['username' => $user, 'password' => $password]);
        $this->get('/');
    }

    /** @return array{0:int,1:string} */
    public function get(string $path): array
    {
        [$code, $body, $headers] = request($this->base . $path, ['header' => $this->headers()]);
        $this->absorb($headers, $body);
        return [$code, $body];
    }

    /** @return array{0:int,1:string} */
    public function post(string $path, array $fields): array
    {
        [$code, $body, $headers] = request($this->base . $path, [
            'method'  => 'POST',
            'header'  => array_merge($this->headers(), ['Content-Type: application/x-www-form-urlencoded']),
            'content' => http_build_query($fields + ['csrf' => $this->csrf]),
        ]);
        $this->absorb($headers, $body);
        return [$code, $body];
    }

    private function headers(): array
    {
        return $this->cookie === '' ? [] : ["Cookie: {$this->cookie}"];
    }

    private function absorb(array $headers, string $body): void
    {
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $m)) $this->cookie = $m[1];
        }
        if (preg_match('/name="csrf" value="([^"]+)"/', $body, $m)) $this->csrf = $m[1];
    }
}

$base = boot($tmp);

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

// --- Upload limit is the php.ini-clamped one, not MAX_UPLOAD_MB ---
//
// .env says 50 MB, php.ini says 1 MB, so 1 MB is the truth the API must report.

echo "upload size limit\n";
// display_errors off, as in production: PHP's own "POST Content-Length exceeds
// the limit" warning would otherwise flush headers before the handler replies.
$clamped = boot($tmp, ['upload_max_filesize' => '1M', 'post_max_size' => '2M', 'display_errors' => '0']);
$auth    = ["Authorization: Bearer $apiSecret"];

[$code, $body] = postFile("$clamped/api/upload", 'big.bin', str_repeat('x', 1_500_000), $auth);
$json = json_decode($body, true);
check('over upload_max_filesize is rejected', 400, $code);
check('error names the effective limit', true, str_contains((string)($json['error'] ?? ''), '1 MB'));
check('max_bytes is the clamped limit, not MAX_UPLOAD_MB', 1024 * 1024, $json['max_bytes'] ?? null);

[$code, $body] = postFile("$clamped/api/upload", 'huge.bin', str_repeat('x', 3_000_000), $auth);
$json = json_decode($body, true);
check('over post_max_size is rejected', 400, $code);
check('over post_max_size is not reported as a missing file', false, str_contains((string)($json['error'] ?? ''), 'No file received'));

[$code, $body] = postFile("$clamped/api/upload", 'ok.bin', str_repeat('x', 1_000), $auth);
check('a file under the limit still uploads', 200, $code);
check('upload lands on disk', true, file_exists($tmp . '/uploads/ok.bin'));

check('missing file is still reported as such', true, str_contains(
    postFile("$clamped/api/upload", '', '', $auth)[1],
    'No file received'
));
check('bad token is still rejected', 403, postFile("$clamped/api/upload", 'x.bin', 'x', ['Authorization: Bearer wrong'])[0]);

// --- Dashboard row actions ---
//
// The expiry select cannot preselect a stored absolute timestamp, so its
// default must be a no-op. It used to default to "Never", which meant
// submitting the row untouched silently cleared the file's expiry.

echo "dashboard row actions\n";
$seed();
// By path, and no ?? — a stored null is a real value here, not a missing one.
$expiry = static function () use ($tmp) {
    foreach (json_decode(file_get_contents($tmp . '/data/files.json'), true) as $entry) {
        if ($entry['path'] === 'kept.txt') return $entry['expires'];
    }
    return 'missing';
};

$browser = new Browser($base);
$browser->login('test', $password);
check('login lands on the dashboard', true, str_contains($browser->get('/')[1], 'Set expiry'));

$browser->post('/expiry/kept.txt', ['expiry' => '7d']);
$sevenDays = $expiry();
check('an explicit expiry is stored', true, is_int($sevenDays) && abs($sevenDays - (time() + 86_400 * 7)) < 60);

$browser->post('/expiry/kept.txt', ['expiry' => '']);
check('submitting the untouched dropdown keeps the expiry', $sevenDays, $expiry());

$browser->post('/expiry/kept.txt', ['expiry' => 'never']);
check('choosing Never still clears it', null, $expiry());

check('a missing file reports itself instead of failing silently', true, str_contains(
    $browser->post('/expiry/ghost.txt', ['expiry' => '1h'])[1] . $browser->get('/')[1],
    'File not found.'
));
check('a missing file did not release the lock into a wedged state', true, str_contains(
    $browser->get('/')[1],
    'kept.txt'
));

$browser->post('/toggle/kept.txt', []);
check('toggle still flips private', true, json_decode(file_get_contents($tmp . '/data/files.json'), true)[1]['private']);

$browser->post('/delete/kept.txt', []);
check('delete still removes the file', false, file_exists($tmp . '/uploads/kept.txt'));
check('delete still removes the metadata entry', false, in_array('kept.txt', array_column(
    json_decode(file_get_contents($tmp . '/data/files.json'), true),
    'path'
), true));

echo $failures === 0 ? "\nPASS\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
