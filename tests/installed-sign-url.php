<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
$formKey = $argv[2] ?? '';
if (!str_starts_with((string) realpath($root), '/tmp/sendrepute-magento-installed.')
    || !preg_match('/^[A-Za-z0-9]{16}$/D', $formKey)) {
    throw new RuntimeException('Refusing unrelated Magento site or invalid form key.');
}
require $root . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
// Use Magento's own encryptor; never print or export the installation key.
echo $om->get(\Magento\Framework\Encryption\EncryptorInterface::class)
    ->getHash('adminhtmlsystem_configedit' . $formKey);