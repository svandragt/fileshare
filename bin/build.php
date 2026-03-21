<?php
declare(strict_types=1);

// Run with: php -d phar.readonly=Off bin/build.php

$srcDir  = dirname(__DIR__) . '/src';
$outDir  = dirname(__DIR__) . '/dist';
$outFile = $outDir . '/fileshare.phar';

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

if (file_exists($outFile)) {
    unlink($outFile);
}

$phar = new Phar($outFile);
$phar->startBuffering();

// Add all src/ files except the dev-only router
$phar->buildFromDirectory($srcDir, '/^(?!.*router\.php).+$/');

$stub = <<<'STUB'
<?php
Phar::mapPhar('fileshare.phar');
require 'phar://fileshare.phar/src/index.php';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();
chmod($outFile, 0755);

// Extract CSS alongside the PHAR so Nginx can serve it as a static file
copy($srcDir . '/simple.min.css', $outDir . '/simple.min.css');

echo "Built: $outFile\n";
echo "Copied: $outDir/simple.min.css\n";
