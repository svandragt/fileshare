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

// Embed CSS as a data URL so the PHAR is fully self-contained
$cssDataUrl  = 'data:text/css;base64,' . base64_encode(file_get_contents($srcDir . '/simple.min.css'));
$cssLinkOld  = '<link rel="stylesheet" href="/simple.min.css">';
$cssLinkNew  = '<link rel="stylesheet" href="' . $cssDataUrl . '">';

$phar = new Phar($outFile);
$phar->startBuffering();

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    if ($file->getFilename() === 'router.php') {
        continue;
    }

    $content      = file_get_contents($file->getPathname());
    $relativePath = 'src/' . ltrim(substr($file->getPathname(), strlen($srcDir)), '/');

    if (str_ends_with($relativePath, '.php')) {
        $content = str_replace($cssLinkOld, $cssLinkNew, $content);
    }

    $phar->addFromString($relativePath, $content);
}

$stub = <<<'STUB'
<?php
Phar::mapPhar('fileshare.phar');
require 'phar://fileshare.phar/src/index.php';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();
chmod($outFile, 0755);

echo "Built: $outFile\n";
