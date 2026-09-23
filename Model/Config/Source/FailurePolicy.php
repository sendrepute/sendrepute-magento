<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class FailurePolicy implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'preserve', 'label' => __('Preserve send on API/validation failure')],
            ['value' => 'block', 'label' => __('Block send on API/validation failure')],
        ];
    }
}