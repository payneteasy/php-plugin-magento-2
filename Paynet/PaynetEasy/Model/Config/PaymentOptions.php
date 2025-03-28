<?php

namespace Paynet\PaynetEasy\Model\Config;

use Magento\Framework\Option\ArrayInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory;

class PaymentOptions implements ArrayInterface
{
    public function toOptionArray()
    {

        $options = [];
        $options[] = [
            'value' => 'direct',
            'label' => 'DIRECT',
        ];
        $options[] = [
            'value' => 'form',
            'label' => 'FORM',
        ];
        return $options;
    }
}
