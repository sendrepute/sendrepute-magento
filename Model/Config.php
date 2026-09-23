<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

final class Config
{
    private const ROOT = 'sendrepute/mail/';

    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    public function enabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::ROOT . 'enabled', ScopeInterface::SCOPE_STORE, $storeId)
            && $this->scopeConfig->isSetFlag(self::ROOT . 'paid_consent', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function riskPolicy(?int $storeId = null): string
    {
        return $this->value('risk_policy', $storeId) === 'block' ? 'block' : 'advisory';
    }

    public function failurePolicy(?int $storeId = null): string
    {
        return $this->value('failure_policy', $storeId) === 'block' ? 'block' : 'preserve';
    }

    public function threshold(?int $storeId = null): float
    {
        $raw = $this->value('threshold', $storeId);
        if (!is_numeric($raw)) {
            throw new \RuntimeException('SendRepute threshold is not numeric.');
        }
        $value = (float) $raw;
        if (!is_finite($value) || $value < 0 || $value > 1) {
            throw new \RuntimeException('SendRepute threshold must be between 0 and 1.');
        }
        return $value;
    }

    public function routeIsOptedIn(string $route, ?int $storeId = null): bool
    {
        if (!in_array($route, $this->routes('opt_in_routes', $storeId), true)) {
            return false;
        }
        // Template identifiers are arbitrary (including numeric IDs), so their
        // security semantics cannot be inferred from names. Every route needs
        // the independent critical-content approval as well.
        return in_array($route, $this->routes('critical_opt_in_routes', $storeId), true);
    }

    private function routes(string $key, ?int $storeId): array
    {
        $values = preg_split('/[\s,]+/', $this->value($key, $storeId), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($values, static fn($v): bool =>
            is_string($v) && (bool) preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D', $v)
        )));
    }

    private function value(string $key, ?int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::ROOT . $key, ScopeInterface::SCOPE_STORE, $storeId);
    }
}