<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Plugin;

use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Psr\Log\LoggerInterface;
use SendRepute\MailAdapter\Exception\BlockedException;
use SendRepute\MailAdapter\Model\ApiClient;
use SendRepute\MailAdapter\Model\Config;
use SendRepute\MailAdapter\Model\DisplayedEmail;
use SendRepute\MailAdapter\Model\RouteRegistry;

final class TransportPlugin
{
    public function __construct(
        private Config $config,
        private RouteRegistry $routes,
        private DisplayedEmail $displayedEmail,
        private ApiClient $client,
        private LoggerInterface $logger
    ) {
    }

    public function aroundSendMessage(TransportInterface $subject, callable $proceed)
    {
        $message = $subject->getMessage();
        $context = $this->routes->consume($message);
        if ($context === null) {
            return $proceed();
        }
        $route = $context['route'];
        $storeId = $context['storeId'];
        if (!$this->config->enabled($storeId) || !$this->config->routeIsOptedIn($route, $storeId)) {
            return $proceed();
        }

        try {
            if (!$message instanceof EmailMessageInterface) {
                throw new \RuntimeException('Final Magento mail object does not implement EmailMessageInterface.');
            }
            $result = $this->client->classify($this->displayedEmail->extract($message));
            $this->logger->info('SendRepute paid pre-send advisory completed.', [
                'route' => $route,
                'request_id' => $result['requestId'],
                'model' => $result['model'],
                'label' => $result['label'],
                'spam_probability' => $result['probability'],
                'charged_millicents' => $result['chargedMillicents'],
                'replayed' => $result['replayed'],
            ]);
            if ($this->config->riskPolicy($storeId) === 'block'
                && $result['probability'] >= $this->config->threshold($storeId)) {
                throw new BlockedException(
                    __('SendRepute blocked this opted-in message at the configured spam threshold.')
                );
            }
        } catch (BlockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('SendRepute pre-send analysis failed.', [
                'route' => $route,
                'exception' => get_class($e),
                'reason' => substr(str_replace(["\r", "\n"], ' ', $e->getMessage()), 0, 300),
            ]);
            if ($this->config->failurePolicy($storeId) === 'block') {
                throw new BlockedException(
                    __('SendRepute failure policy blocked this opted-in message.'),
                    $e
                );
            }
        }

        // The original transport and immutable final message are always used.
        return $proceed();
    }
}