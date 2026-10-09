<?php
declare(strict_types=1);

$contracts = json_decode(file_get_contents(__DIR__ . '/contracts.json'), true, 16, JSON_THROW_ON_ERROR);
if (array_keys($contracts['patchlines']) !== ['2.4.7-p5', '2.4.7-p6']) {
    throw new RuntimeException('exact patchline evidence changed');
}
$expected = [
    'TransportInterface.php' => 'b39036471c01cbc3469368faa8fcdbf906b8186bd5f446fe732eeb297eb283ac',
    'EmailMessageInterface.php' => 'c99f16bd208e763798184d3b2cf48a79c4f976eed206fb6099867b8fe48ae1c1',
    'MimeMessageInterface.php' => '0e8be973cb2695d2cae2f830d165a11f8078c875e4e50485b4425d4887869ed6',
    'MimePartInterface.php' => 'd668ac96d2ec2d8a3ad19452477278299b635730d70446314770da3e8c9bb649',
];
foreach ($contracts['patchlines'] as $patch => $record) {
    if (!preg_match('/^[0-9a-f]{40}$/D', $record['commit']) || $record['files'] !== $expected) {
        throw new RuntimeException('immutable source contract evidence invalid for ' . $patch);
    }
}

$di = file_get_contents(dirname(__DIR__) . '/etc/di.xml');
$plugin = file_get_contents(dirname(__DIR__) . '/Plugin/TransportPlugin.php');
if (!str_contains($di, 'Magento\Framework\Mail\TransportInterface')
    || !str_contains($plugin, 'aroundSendMessage')
    || !str_contains($plugin, '$subject->getMessage()')) {
    throw new RuntimeException('final transport hook contract missing');
}
$acl = new DOMDocument();
$system = new DOMDocument();
if (!$acl->load(dirname(__DIR__) . '/etc/acl.xml')
    || !$system->load(dirname(__DIR__) . '/etc/adminhtml/system.xml')) {
    throw new RuntimeException('Magento native admin XML is malformed');
}
$aclPath = '/config/acl/resources/resource[@id="Magento_Backend::admin"]'
    . '/resource[@id="Magento_Backend::stores"]'
    . '/resource[@id="Magento_Backend::stores_settings"]'
    . '/resource[@id="Magento_Config::config"]'
    . '/resource[@id="SendRepute_MailAdapter::config"]';
if ((new DOMXPath($acl))->query($aclPath)->length !== 1
    || (new DOMXPath($system))->query('/config/system/tab[@id="services"]')->length !== 1
    || (new DOMXPath($system))->query('/config/system/section[@id="sendrepute"]/resource[text()="SendRepute_MailAdapter::config"]')->length !== 1) {
    throw new RuntimeException('Installed Magento ACL hierarchy or Services configuration tab regressed');
}
$threshold = file_get_contents(dirname(__DIR__) . '/Model/Config/Backend/Threshold.php');
if (!preg_match('/(?<!final )class Threshold extends Value/', $threshold)
    || preg_match('/final\s+class\s+Threshold\b/', $threshold)) {
    throw new RuntimeException('Intercepted native Magento threshold backend must not be final');
}
echo "Immutable Magento 2.4.7-p5/p6 contract records and final hook verified.\n";