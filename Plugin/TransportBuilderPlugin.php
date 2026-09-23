<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Plugin;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use SendRepute\MailAdapter\Model\RouteRegistry;

final class TransportBuilderPlugin
{
    public function __construct(private RouteRegistry $registry)
    {
    }

    public function afterSetTemplateIdentifier(
        TransportBuilder $subject,
        TransportBuilder $result,
        $templateIdentifier
    ): TransportBuilder {
        if (is_string($templateIdentifier) || is_int($templateIdentifier)) {
            $this->registry->rememberRoute($subject, (string) $templateIdentifier);
        }
        return $result;
    }

    public function afterSetTemplateOptions(
        TransportBuilder $subject,
        TransportBuilder $result,
        array $templateOptions
    ): TransportBuilder {
        $store = $templateOptions['store'] ?? null;
        if (is_object($store) && method_exists($store, 'getId')) {
            $store = $store->getId();
        }
        $storeId = filter_var($store, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($storeId !== false) {
            $this->registry->rememberStore($subject, $storeId);
        } else {
            // Never retain a prior builder store when new options are missing
            // or unsupported.
            $this->registry->rememberStore($subject, -1);
        }
        return $result;
    }

    public function aroundGetTransport(TransportBuilder $subject, callable $proceed): TransportInterface
    {
        try {
            $result = $proceed();
            if (!$result instanceof TransportInterface) {
                throw new \RuntimeException('Magento returned an unsupported transport.');
            }
            $this->registry->bind($subject, $result);
            return $result;
        } finally {
            // Required even when transport construction throws in a long-lived worker.
            $this->registry->forget($subject);
        }
    }
}