<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$output = $root . '/dist/sendrepute-magento-0.1.1.zip';
$allowedTop = [
    'composer.json', 'LICENSE', 'manifest.json', 'README.md', 'registration.php', 'SECURITY.md',
];
$allowedDirs = ['Exception', 'Model', 'Plugin', 'etc'];
$files = $allowedTop;
foreach ($allowedDirs as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (!preg_match('/\.(?:php|xml)$/D', $relative)) {
            throw new RuntimeException('Unexpected production file in allowlisted directory: ' . $relative);
        }
        $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
sort($files, SORT_STRING);
foreach ($files as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Missing package file: ' . $relative);
    }
}

if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0775, true) && !is_dir(dirname($output))) {
    throw new RuntimeException('Could not create dist directory.');
}
foreach (glob($root . '/dist/sendrepute-magento-*.zip') ?: [] as $oldArchive) {
    if ($oldArchive !== $output) {
        @unlink($oldArchive);
    }
}
@unlink($output);
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('Could not create source archive.');
}
foreach ($files as $relative) {
    $name = 'SendRepute_MailAdapter/' . $relative;
    if (!$zip->addFile($root . '/' . $relative, $name)) {
        throw new RuntimeException('Could not add ' . $relative);
    }
    $zip->setMtimeName($name, 315532800);
    $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
}
if (!$zip->close()) {
    throw new RuntimeException('Could not finalize source archive.');
}
echo $output . PHP_EOL;