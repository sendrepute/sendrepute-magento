<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Tests;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use SendRepute\MailAdapter\Model\Config;
use SendRepute\MailAdapter\Model\ApiClient;
use SendRepute\MailAdapter\Model\DisplayedEmail;
use SendRepute\MailAdapter\Model\RouteRegistry;
use SendRepute\MailAdapter\Plugin\TransportBuilderPlugin;
use SendRepute\MailAdapter\Plugin\TransportPlugin;
use Psr\Log\LoggerInterface;

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/Model/Config.php';
require dirname(__DIR__) . '/Model/RouteRegistry.php';
require dirname(__DIR__) . '/Plugin/TransportBuilderPlugin.php';
require dirname(__DIR__) . '/Exception/BlockedException.php';
require dirname(__DIR__) . '/Plugin/TransportPlugin.php';

final class ScopeFixture implements ScopeConfigInterface
{
    public array $seenScopes = [];
    public function __construct(private array $values) {}
    public function getValue($path = null, $scopeType = null, $scopeCode = null)
    {
        $this->seenScopes[] = $scopeCode;
        return $this->values[(int) $scopeCode][$path] ?? '';
    }
    public function isSetFlag($path, $scopeType = null, $scopeCode = null)
    {
        $this->seenScopes[] = $scopeCode;
        return (bool) ($this->values[(int) $scopeCode][$path] ?? false);
    }
}

final class LoggerFixture implements LoggerInterface
{
    public function info($message, array $context = []) {}
    public function error($message, array $context = []) {}
}

final class CountingApiClient extends ApiClient
{
    public int $calls = 0;

    public function classify(array $payload): array
    {
        $this->calls++;
        return [
            'requestId' => 'offline_fixture',
            'model' => 'thor',
            'probability' => 0.1,
            'label' => 'inbox',
            'chargedMillicents' => 0,
            'replayed' => true,
        ];
    }
}

final class TransportFixture implements TransportInterface
{
    public function __construct(private object $message) {}
    public function sendMessage() {}
    public function getMessage() { return $this->message; }
}

$root = 'sendrepute/mail/';
$scope = new ScopeFixture([
    0 => [
        $root . 'enabled' => 1,
        $root . 'paid_consent' => 1,
        $root . 'opt_in_routes' => 'mfa_code',
        $root . 'critical_opt_in_routes' => 'mfa_code',
    ],
    7 => [
        $root . 'enabled' => 0,
        $root . 'paid_consent' => 0,
        $root . 'opt_in_routes' => 'mfa_code',
        $root . 'critical_opt_in_routes' => 'mfa_code',
    ],
    8 => [
        $root . 'enabled' => 1,
        $root . 'paid_consent' => 1,
        $root . 'opt_in_routes' => '123 recovery_email ordinary',
        $root . 'critical_opt_in_routes' => '123 recovery_email',
    ],
]);
$config = new Config($scope);
if ($config->enabled(7) || !$config->enabled(8)) {
    throw new \RuntimeException('message store scope was not used for consent');
}
if (!$config->routeIsOptedIn('123', 8) || !$config->routeIsOptedIn('recovery_email', 8)
    || $config->routeIsOptedIn('ordinary', 8)) {
    throw new \RuntimeException('independent second route approval was bypassed');
}

$registry = new RouteRegistry();
$plugin = new TransportBuilderPlugin($registry);
$builder = new TransportBuilder();
$plugin->afterSetTemplateIdentifier($builder, $builder, 'mfa_code');
$plugin->afterSetTemplateOptions($builder, $builder, ['area' => 'frontend', 'store' => '7']);
$message = new \stdClass();
$transport = new TransportFixture($message);
$returned = $plugin->aroundGetTransport($builder, fn() => $transport);
$context = $registry->consume($returned->getMessage());
if ($context !== ['route' => 'mfa_code', 'storeId' => 7]) {
    throw new \RuntimeException('template route and store scope were not correlated');
}

$plugin->afterSetTemplateIdentifier($builder, $builder, 'stale_route');
$plugin->afterSetTemplateOptions($builder, $builder, ['store' => 8]);
try {
    $plugin->aroundGetTransport($builder, function () {
        throw new \RuntimeException('construction failed');
    });
    throw new \RuntimeException('transport construction failure was swallowed');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== 'construction failed') {
        throw $e;
    }
}
$laterMessage = new \stdClass();
$registry->bind($builder, new TransportFixture($laterMessage));
if ($registry->consume($laterMessage) !== null) {
    throw new \RuntimeException('failed transport left stale route state');
}

// Exercise the actual final transport plugin: store 7 is disabled even though
// default scope 0 is enabled, so it must delegate without a paid call.
$plugin->afterSetTemplateIdentifier($builder, $builder, 'mfa_code');
$plugin->afterSetTemplateOptions($builder, $builder, ['store' => 7]);
$scopedMessage = new \stdClass();
$scopedTransport = new TransportFixture($scopedMessage);
$plugin->aroundGetTransport($builder, fn() => $scopedTransport);
$finalPlugin = new TransportPlugin(
    $config,
    $registry,
    new DisplayedEmail(),
    new ApiClient(),
    new LoggerFixture()
);
$delegated = 0;
$finalPlugin->aroundSendMessage($scopedTransport, function () use (&$delegated) {
    $delegated++;
    return 'delegated';
});
if ($delegated !== 1 || in_array(null, $scope->seenScopes, true)
    || !in_array(7, $scope->seenScopes, true)) {
    throw new \RuntimeException('final transport plugin did not enforce correlated store scope');
}

// A successful getTransport can be abandoned without sendMessage. Its message
// correlation must vanish with the object, never survive as a reusable numeric
// object ID in a long-running worker.
$plugin->afterSetTemplateIdentifier($builder, $builder, '123');
$plugin->afterSetTemplateOptions($builder, $builder, ['store' => 8]);
$abandonedMessage = new Message([new Part('text/plain', 'abandoned')]);
$abandonedTransport = new TransportFixture($abandonedMessage);
$plugin->aroundGetTransport($builder, fn() => $abandonedTransport);
unset($abandonedTransport, $abandonedMessage);
gc_collect_cycles();

$unrelatedMessage = new Message([new Part('text/plain', 'unrelated')]);
$unrelatedTransport = new TransportFixture($unrelatedMessage);
$countingClient = new CountingApiClient();
$unrelatedPlugin = new TransportPlugin(
    $config,
    $registry,
    new DisplayedEmail(),
    $countingClient,
    new LoggerFixture()
);
$unrelatedDelegated = 0;
$unrelatedPlugin->aroundSendMessage($unrelatedTransport, function () use (&$unrelatedDelegated) {
    $unrelatedDelegated++;
});
if ($countingClient->calls !== 0 || $unrelatedDelegated !== 1) {
    throw new \RuntimeException('abandoned successful transport authorized an unrelated paid call');
}

echo "Store scope, double approval, failure cleanup, and abandoned-message WeakMap cleanup verified.\n";