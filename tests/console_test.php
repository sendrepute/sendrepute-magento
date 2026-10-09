<?php
declare(strict_types=1);

// Offline tests for the Magento admin service console (no Magento runtime, no network).

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'SendRepute\\MailAdapter\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use SendRepute\MailAdapter\Model\ConsoleGateway;
use SendRepute\MailAdapter\ServiceConsole\FileLockStore;

$n = 0;
$ok = static function ($c, string $l) use (&$n): void {
    if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); }
    $n++;
};

// Native registration.
$routes = simplexml_load_file($root . '/etc/adminhtml/routes.xml');
$route = $routes->xpath('/config/router[@id="admin"]/route[@id="sendrepute"]')[0] ?? null;
$ok($route && (string) $route['frontName'] === 'sendrepute' && (string) $route->module['name'] === 'SendRepute_MailAdapter' && (string) $route->module['before'] === 'Magento_Backend', 'admin route registered');
$menu = simplexml_load_file($root . '/etc/adminhtml/menu.xml');
$item = $menu->xpath('/config/menu/add[@id="SendRepute_MailAdapter::console"]')[0] ?? null;
$ok($item && (string) $item['action'] === 'sendrepute/console/index' && (string) $item['resource'] === 'SendRepute_MailAdapter::console', 'menu item with ACL');
$acl = simplexml_load_file($root . '/etc/acl.xml');
$ok(count($acl->xpath('//resource[@id="Magento_Backend::admin"]//resource[@id="SendRepute_MailAdapter::console"]')) === 1, 'ACL resource declared');
$ok(count($acl->xpath('//resource[@id="SendRepute_MailAdapter::config"]')) === 1, 'existing config ACL preserved');
$src = (string) file_get_contents($root . '/Controller/Adminhtml/Console/Index.php');
$ok(str_contains($src, "ADMIN_RESOURCE = 'SendRepute_MailAdapter::console'") && str_contains($src, 'extends Action') && str_contains($src, '_formKeyValidator->validate($request)'), 'controller ACL and form key');
$ok(str_contains($src, 'HttpPostActionInterface') && str_contains($src, 'HttpGetActionInterface'), 'controller HTTP verbs');
$ok(str_contains((string) file_get_contents($root . '/scripts/package.php'), "'ServiceConsole'"), 'package includes console');

$calls = [];
$fixtures = [
    'GET /v1/account' => ['username' => 'magento-fixture'],
    'GET /v1/vip' => ['active' => true, 'regularAiTemplatePriceMillicents' => 199000, 'aiTemplatePriceMillicents' => 99000, 'templateAccessPriceMillicents' => 50000],
    'GET /v1/vip/plans' => ['monthlyPriceMillicents' => 900000],
    'POST /v1/vip/purchase' => ['active' => true],
    'POST /v1/email-builder/export' => ['valid' => true, 'filename' => 'm', 'html' => '<p>m</p>'],
    'GET /customer/paid-results/identity' => ['credentialId' => 'cred-mg'],
];
$transport = static function ($m, $p, $b, $max) use (&$calls, $fixtures) {
    $calls[] = "$m $p";
    return isset($fixtures["$m $p"]) ? ['status' => 200, 'data' => $fixtures["$m $p"], 'error' => ''] : ['status' => 404, 'data' => null, 'error' => 'Not found'];
};
$dir = sys_get_temp_dir() . '/sr-mg-console-' . bin2hex(random_bytes(5));
$g = new ConsoleGateway($transport, new FileLockStore($dir), 'secret', 'magento-1');
$tabUrl = static fn (string $t): string => '/admin/sendrepute/console/index/tab/' . $t . '/key/abc/';
$run = static fn (bool $isPost, bool $valid, array $post, string $tab = 'account') => $g->run($isPost, $valid, $post, $tab, '/admin/sendrepute/console/index/key/abc/', 'FK123', $tabUrl);

foreach (['account', 'billing', 'catalog', 'ai', 'builder', 'recovery'] as $t) {
    $calls = [];
    $html = $run(false, false, [], $t)['html'];
    $ok(str_contains($html, 'name="form_key" value="FK123"') && !str_contains($html, '<script'), "$t form key, no script");
    $ok(array_diff($calls, ['GET /v1/vip', 'GET /v1/vip/plans']) === [], "$t GET reads prices only");
}
$calls = [];
$html = $run(true, false, ['op' => 'account'])['html'];
$ok(!in_array('GET /v1/account', $calls, true) && str_contains($html, 'form key'), 'invalid form key refused');
$ok(str_contains($run(true, true, ['op' => 'account'])['html'], 'magento-fixture'), 'read');
$ok(str_contains($run(true, true, ['op' => 'paid_identity'])['html'], 'cred-mg'), 'recovery identity');
$paid = ['op' => 'vip_purchase', 'paid_consent' => '1', 'expected_price' => '900000'];
$calls = [];
$run(true, true, $paid);
$ok(count(array_keys($calls, 'POST /v1/vip/purchase')) === 1, 'paid once');
$calls = [];
$html = $run(true, true, $paid)['html'];
$ok(!in_array('POST /v1/vip/purchase', $calls, true) && str_contains($html, 'already submitted'), 'durable replay lock');
$calls = [];
$run(true, true, ['expected_price' => '1'] + $paid);
$ok(!in_array('POST /v1/vip/purchase', $calls, true), 'stale price refused');
$d = $run(true, true, ['op' => 'native_export', 'document' => '{}', 'access_id' => 'acc']);
$ok(isset($d['html']), 'native export validation path');
$d = $run(true, true, ['op' => 'mjml_export', 'mjml' => '<mjml></mjml>', 'format' => 'html']);
$ok($d['headers']['Content-Type'] === 'application/octet-stream' && $d['headers']['X-Content-Type-Options'] === 'nosniff' && $d['file'][0] === 'm.html', 'safe download');
$ok(ConsoleGateway::API_BASE === 'https://www.sendrepute.com/api', 'fixed origin');

array_map('unlink', glob($dir . '/*.lock') ?: []);
@rmdir($dir);
echo "magento console_test: $n assertions passed\n";
