<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/package.php');
exec($command, $output, $status);
if ($status !== 0) {
    throw new RuntimeException('first package build failed');
}
$archive = $root . '/dist/sendrepute-magento-0.1.1.zip';
$first = hash_file('sha256', $archive);
exec($command, $output, $status);
if ($status !== 0 || hash_file('sha256', $archive) !== $first) {
    throw new RuntimeException('archive is not deterministic');
}
$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    throw new RuntimeException('archive cannot be opened');
}
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (!str_starts_with($name, 'SendRepute_MailAdapter/')
        || str_contains($name, '/tests/')
        || str_contains($name, '/scripts/')
        || str_contains($name, '/vendor/')
        || preg_match('/(?:\.env|credential|cache|token)/i', $name)) {
        throw new RuntimeException('unsafe archive member: ' . $name);
    }
}
foreach ([
    'composer.json', 'registration.php', 'etc/module.xml', 'etc/di.xml',
    'README.md', 'SECURITY.md', 'LICENSE', 'manifest.json',
] as $required) {
    if ($zip->locateName('SendRepute_MailAdapter/' . $required) === false) {
        throw new RuntimeException('archive missing ' . $required);
    }
}
$zip->close();
echo "Deterministic allowlisted production source archive verified: {$first}\n";