<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\ValidatorException;

class Threshold extends Value
{
    public function beforeSave()
    {
        $raw = $this->getValue();
        if (!is_numeric($raw)) {
            throw new ValidatorException(__('Threshold must be a number from 0 through 1.'));
        }
        $value = (float) $raw;
        if (!is_finite($value) || $value < 0 || $value > 1) {
            throw new ValidatorException(__('Threshold must be a finite number from 0 through 1.'));
        }
        $this->setValue((string) $value);
        return parent::beforeSave();
    }
}