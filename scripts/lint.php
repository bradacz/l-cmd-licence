<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
$count = 0;
foreach (['src', 'public', 'scripts', 'tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            fwrite(STDERR, implode("\n", $output) . "\n");
            $failed = true;
        }
        $output = [];
        $count++;
    }
}
echo "Checked {$count} PHP files.\n";
exit($failed ? 1 : 0);
