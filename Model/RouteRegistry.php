<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Model;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;

/**
 * Request-local correlation only. A route is consumed once at sendMessage().
 */
final class RouteRegistry
{
    /** @var \WeakMap<TransportBuilder,array{route?:string,storeId?:int}> */
    private \WeakMap $builders;

    /** @var \WeakMap<object,array{route:string,storeId:int}> */
    private \WeakMap $messages;

    public function __construct()
    {
        // Object keys disappear automatically when an abandoned builder or
        // successfully-built-but-never-sent message is destroyed. Numeric
        // spl_object_id keys can be reused in long-lived workers.
        $this->builders = new \WeakMap();
        $this->messages = new \WeakMap();
    }

    public function rememberRoute(TransportBuilder $builder, string $route): void
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D', $route)) {
            unset($this->builders[$builder]);
            return;
        }
        $context = $this->builders[$builder] ?? [];
        $context['route'] = $route;
        $this->builders[$builder] = $context;
    }

    public function rememberStore(TransportBuilder $builder, int $storeId): void
    {
        if ($storeId < 0) {
            unset($this->builders[$builder]);
            return;
        }
        $context = $this->builders[$builder] ?? [];
        $context['storeId'] = $storeId;
        $this->builders[$builder] = $context;
    }

    public function bind(TransportBuilder $builder, TransportInterface $transport): void
    {
        $context = $this->builders[$builder] ?? null;
        if (!isset($context['route'], $context['storeId'])) {
            return;
        }
        $this->messages[$transport->getMessage()] = [
            'route' => $context['route'],
            'storeId' => $context['storeId'],
        ];
    }

    public function forget(TransportBuilder $builder): void
    {
        unset($this->builders[$builder]);
    }

    /** @return array{route:string,storeId:int}|null */
    public function consume(object $message): ?array
    {
        $route = $this->messages[$message] ?? null;
        unset($this->messages[$message]);
        return $route;
    }
}