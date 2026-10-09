<?php
declare(strict_types=1);

// Run only against the runner-owned installed Magento root and local mail sink.
$root = $argv[1] ?? '';
$mode = $argv[2] ?? 'initial';
if (!is_file($root . '/app/bootstrap.php') || !is_file($root . '/app/etc/env.php')) {
    throw new RuntimeException('A completed disposable Magento install is required.');
}
if (!str_starts_with(realpath($root), '/tmp/sendrepute-magento-installed.')
    || !is_dir(getenv('MAGENTO_MAIL_SINK_DIR') ?: '')) {
    throw new RuntimeException('Refusing non-disposable host or missing local mail sink.');
}
require $root . '/app/bootstrap.php';

class Task600SyntheticClient extends \SendRepute\MailAdapter\Model\ApiClient
{
    public static string $mode = 'low';
    public function classify(array $payload): array
    {
        if (self::$mode === 'throw') {
            throw new RuntimeException('Synthetic classifier failure, no network request.');
        }
        if (!str_contains($payload['body'], 'Task600 synthetic displayed content')) {
            throw new RuntimeException('Native MIME body was not passed to the synthetic classifier.');
        }
        $log = getenv('MAGENTO_CLASSIFY_LOG');
        if (!$log || !str_starts_with($log, '/tmp/sendrepute-magento-installed.')) {
            throw new RuntimeException('Missing owned synthetic classification log.');
        }
        file_put_contents($log, json_encode(['body' => $payload['body'], 'mode' => self::$mode]) . "\n", FILE_APPEND | LOCK_EX);
        return [
            'requestId' => 'synthetic_task600',
            'model' => 'thor',
            'probability' => self::$mode === 'high' ? 0.99 : 0.01,
            'label' => self::$mode === 'high' ? 'spam' : 'inbox',
            'chargedMillicents' => 0,
            'replayed' => false,
        ];
    }
}
$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->configure(['preferences' => [
    \SendRepute\MailAdapter\Model\ApiClient::class => Task600SyntheticClient::class,
]]);
$state = $om->get(\Magento\Framework\App\State::class);
$state->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
$writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$cache = $om->get(\Magento\Framework\App\Cache\Manager::class);

$idFile = dirname($root) . '/template-id';
if ($mode === 'initial') {
    $template = $om->create(\Magento\Email\Model\Template::class);
    $template->setTemplateCode('Task600 synthetic local mail ' . bin2hex(random_bytes(5)));
    $template->setTemplateText('Task600 synthetic displayed content');
    $template->setTemplateSubject('Task600 subject');
    $template->setTemplateType(1);
    $template->save();
    file_put_contents($idFile, (string) $template->getId());
}
$id = trim((string) file_get_contents($idFile));
if (!ctype_digit($id)) {
    throw new RuntimeException('Owned Magento fixture template is missing.');
}
$scope = 'stores';
$store = (int) $om->get(\Magento\Store\Model\StoreManagerInterface::class)->getDefaultStoreView()->getId();
if ($store < 1) {
    throw new RuntimeException('Installed Magento has no default store view.');
}
$set = static function (string $field, string $value) use ($writer, $scope, $store): void {
    $writer->save('sendrepute/mail/' . $field, $value, $scope, $store);
};
$make = static function () use ($om, $id, $store): \Magento\Framework\Mail\TransportInterface {
    $builder = $om->create(\Magento\Framework\Mail\Template\TransportBuilder::class);
    return $builder->setTemplateIdentifier($id)
        ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND, 'store' => $store])
        ->setTemplateVars([])
        ->setFromByScope(['name' => 'Synthetic Local', 'email' => 'sender@example.invalid'], $store)
        ->addTo('recipient@example.invalid')
        ->getTransport();
};
$sink = getenv('MAGENTO_MAIL_SINK_DIR');
$log = getenv('MAGENTO_CLASSIFY_LOG');
$delivered = static fn(): int => count(glob($sink . '/message.*'));
$classified = static fn(): int => is_file($log) ? count(file($log)) : 0;
if ($mode === 'worker') {
    Task600SyntheticClient::$mode = 'low';
    $make()->sendMessage();
    echo "Concurrent installed-host worker sent to local sink.\n";
    exit;
}
if ($mode === 'prepare') {
    $set('enabled', '1');
    $set('paid_consent', '1');
    $set('opt_in_routes', $id);
    $set('critical_opt_in_routes', $id);
    $set('risk_policy', 'advisory');
    $set('failure_policy', 'block');
    $cache->clean(['config']);
    echo "Synthetic concurrent opted-in send prepared.\n";
    exit;
}
if ($mode === 'finish') {
    $set('enabled', '0');
    $set('paid_consent', '0');
    $set('opt_in_routes', '');
    $set('critical_opt_in_routes', '');
    $cache->clean(['config']);
    echo "Synthetic concurrent send consent removed.\n";
    exit;
}
if ($mode !== 'initial') {
    throw new RuntimeException('Unknown installed-host test mode.');
}
$set('enabled', '1');
$set('paid_consent', '1');
$set('opt_in_routes', $id);
$set('critical_opt_in_routes', $id);
$set('failure_policy', 'block');
$set('risk_policy', 'block');
$set('threshold', '0.5');
$cache->clean(['config']);
try {
    Task600SyntheticClient::$mode = 'throw';
    $transport = $make();
    $message = $transport->getMessage();
    try {
        $transport->sendMessage();
        throw new RuntimeException('Final mail hook failed to block synthetic classification failure.');
    } catch (\SendRepute\MailAdapter\Exception\BlockedException $e) {
        if ($transport->getMessage() !== $message) {
            throw new RuntimeException('Native transport message was changed.');
        }
    }
    $baselineDelivery = $delivered();
    $baselineClassify = $classified();
    Task600SyntheticClient::$mode = 'high';
    try {
        $make()->sendMessage();
        throw new RuntimeException('High-risk synthetic classifier did not cancel the native send.');
    } catch (\SendRepute\MailAdapter\Exception\BlockedException $e) {
    }
    if ($delivered() !== $baselineDelivery || $classified() !== $baselineClassify + 1) {
        throw new RuntimeException('High-risk cancellation did not occur before local delivery.');
    }
    Task600SyntheticClient::$mode = 'low';
    $make()->sendMessage();
    if ($delivered() !== $baselineDelivery + 1 || $classified() !== $baselineClassify + 2) {
        throw new RuntimeException('Allowed opted-in message did not reach the local sink exactly once.');
    }
    $mail = file_get_contents(glob($sink . '/message.*')[array_key_last(glob($sink . '/message.*'))]);
    if (!str_contains($mail, 'Task600 synthetic displayed content')) {
        throw new RuntimeException('Local sink did not receive unchanged synthetic displayed content.');
    }
    // Removing independent approval must bypass classification and deliver.
    $set('critical_opt_in_routes', '');
    $cache->clean(['config']);
    $make()->sendMessage();
    if ($delivered() !== $baselineDelivery + 2 || $classified() !== $baselineClassify + 2) {
        throw new RuntimeException('Unselected mail was classified or not delivered.');
    }
    $set('critical_opt_in_routes', $id);
    $set('paid_consent', '0');
    $cache->clean(['config']);
    $make()->sendMessage();
    if ($delivered() !== $baselineDelivery + 3 || $classified() !== $baselineClassify + 2) {
        throw new RuntimeException('Consent-off mail was classified or not delivered.');
    }
    echo "Installed final hook risk block, failure block, consent-off/unselected bypass and local delivery passed.\n";
} finally {
    $set('enabled', '0');
    $set('paid_consent', '0');
    $set('opt_in_routes', '');
    $set('critical_opt_in_routes', '');
    $set('failure_policy', 'preserve');
    $cache->clean(['config']);
}