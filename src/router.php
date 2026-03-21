<?php
// Router for PHP built-in dev server (composer serve)
// Returns false to let the server serve static files directly,
// otherwise routes everything through index.php.
$file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
