<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class RiskPolicy implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'advisory', 'label' => __('Advisory (never block on result)')],
            ['value' => 'block', 'label' => __('Block at or above threshold')],
        ];
    }
}