<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Model;

use SendRepute\MailAdapter\ServiceConsole\Contract;
use SendRepute\MailAdapter\ServiceConsole\LockStore;
use SendRepute\MailAdapter\ServiceConsole\Renderer;
use SendRepute\MailAdapter\ServiceConsole\Service;

/**
 * Framework-free core of the Magento admin service console. The admin
 * controller performs ACL (SendRepute_MailAdapter::console) and form key
 * validation and passes the verdict in; nothing is sent without both.
 */
class ConsoleGateway
{
    public const API_BASE = 'https://www.sendrepute.com/api';

    /** @var callable */
    private $transport;

    public function __construct(callable $transport, private LockStore $locks, private string $secret, private string $actor)
    {
        $this->transport = $transport;
    }

    /**
     * @param array<string, mixed> $post
     * @param callable(string): string $tabUrl
     * @return array{html:string}|array{file:array{0:string,1:string,2:string}, headers:array<string,string>}
     */
    public function run(bool $isPost, bool $formKeyValid, array $post, string $tab, string $action, string $formKey, callable $tabUrl): array
    {
        $service = new Service($this->transport, $this->locks, $this->secret, $this->actor, ['hostedReturnOrigin' => (string) getenv('SENDREPUTE_HOSTED_RETURN_ORIGIN')]);
        $state = [];
        if ($isPost) {
            if (!$formKeyValid) {
                $state = ['error' => 'The form key expired or is invalid. Reload the page and try again. Nothing was submitted.'];
            } elseif (Service::isExport((string) ($post['op'] ?? ''))) {
                $out = $service->download($post);
                if (isset($out['file'])) {
                    [$name, $type, $bytes] = $out['file'];
                    return ['file' => $out['file'], 'headers' => Contract::downloadHeaders($name, $type, strlen($bytes))];
                }
                ['tab' => $tab, 'state' => $state] = $out;
            } else {
                ['tab' => $tab, 'state' => $state] = $service->handle($post);
            }
        }
        $renderer = new Renderer([
            'action' => $action,
            'hidden' => ['form_key' => $formKey],
            'tabUrl' => $tabUrl,
            'price' => static fn (string $op) => $service->priceRead($op),
            'hostedOrigin' => $service->hostedOrigin(),
            'platform' => 'Magento',
            'primary' => 'action-primary sr-btn sr-btn-primary',
            'secondary' => 'action-secondary sr-btn',
        ]);
        return ['html' => $renderer->page($tab, $state)];
    }
}
