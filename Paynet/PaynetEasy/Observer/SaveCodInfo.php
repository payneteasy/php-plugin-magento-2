<?php

namespace Paynet\PaynetEasy\Observer;

use Magento\OfflinePayments\Model\Cashondelivery;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use Paynet\PaynetEasy\Helper\PaynetEasyLogger;
use Paynet\PaynetEasy\Model\PaynetEasy;
use Paynet\PaynetEasy\Gateway\Http\Client\PaynetApi;
/**
 * Class SavePoNumberToOrderObserver
 */
class SaveCodInfo extends AbstractDataAssignObserver
{
    const CREDIT_CARD_NUMBER = 'credit_card_number';
    const CARD_PRINTED_NAME = 'card_printed_name';
    const EXPIRE_MONTH = 'expire_month';
    const EXPIRE_YEAR = 'expire_year';
    const CVV_2 = 'cvv2';
    /**
     * @var array
     */
    protected $additionalInformationList = [
        self::CREDIT_CARD_NUMBER,
        self::CARD_PRINTED_NAME,
        self::EXPIRE_MONTH,
        self::EXPIRE_YEAR,
        self::CVV_2
    ];


    protected $logger;


    public function __construct(

        \Psr\Log\LoggerInterface $logger

    ) {

        $this->logger = $logger;

    }
    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $data = $this->readDataArgument($observer);
        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_array($additionalData)) {
            return;
        }

        $paymentInfo = $this->readPaymentModelArgument($observer);

        foreach ($this->additionalInformationList as $additionalInformationKey) {
            if (isset($additionalData[$additionalInformationKey])) {
                $paymentInfo->setData(
                    $additionalInformationKey,
                    $additionalData[$additionalInformationKey]
                );
            }
        }
    }
}