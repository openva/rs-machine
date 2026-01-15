<?php

$root = dirname(__DIR__);
$vendorRoot = $root . '/includes/vendor/openva/rs-video-processor';
$targetDir = $vendorRoot . '/includes';
$targetFile = $targetDir . '/functions.inc.php';
$sourceFile = $root . '/includes/functions.inc.php';

if (!file_exists($vendorRoot)) {
    exit(0);
}

if (file_exists($targetFile)) {
    exit(0);
}

if (!file_exists($sourceFile)) {
    fwrite(STDERR, "Missing source functions file at {$sourceFile}; skipping stub creation.\n");
    exit(0);
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "Failed to create directory {$targetDir}\n");
    exit(1);
}

$relativePath = '../../../../functions.inc.php';
$contents = "<?php\nrequire_once __DIR__ . '/{$relativePath}';\n";

if (file_put_contents($targetFile, $contents) === false) {
    fwrite(STDERR, "Failed to write {$targetFile}\n");
    exit(1);
}
