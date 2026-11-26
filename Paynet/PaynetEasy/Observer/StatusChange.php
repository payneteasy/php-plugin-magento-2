<?php

namespace Paynet\PaynetEasy\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Message\ManagerInterface;
use Paynet\PaynetEasy\Helper\PaynetEasyLogger;
use Paynet\PaynetEasy\Model\PaynetEasy;
use Paynet\PaynetEasy\Gateway\Http\Client\PaynetApi;

class StatusChange implements ObserverInterface
{
    /**
     * @var ObjectManagerInterface
     */
    protected $_objectManager;
    protected $messageManager;
    protected $paynetEasyLogger;
    protected $_scopeConfig;
    protected $paynetApi;
    public const PAYMENT_METHOD_PAYNETEASY_CODE = 'paynet_payneteasy';
    public const PAYMENT_METHOD_PAYNETEASY_XML_PATH = 'payment/paynet_payneteasy/';
    /**
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Sales\Model\Order $order,
        ManagerInterface $messageManager,
        PaynetEasyLogger $paynetEasyLogger,
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->_objectManager = $objectManager;
        $this->messageManager = $messageManager;
        $this->paynetEasyLogger = $paynetEasyLogger;
        $this->_scopeConfig = $scopeConfig;
        $this->setPaynetApi($scopeConfig);
    }

    protected function setPaynetApi($scopeConfig)
    {
        $arFields = [
            'endpoint_id',
            'control_key',
            'test_mode',
            'merchant_login',
            'payment_method'
        ];

        $arFieldsData = [];

        foreach ($arFields as $arField) {
            $arFieldsData[$arField] = trim($scopeConfig->getValue(
                self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_' . $arField,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            ));
        }

        $this->paynetApi = new PaynetApi(
            $arFieldsData['merchant_login'],
            $arFieldsData['control_key'],
            $arFieldsData['endpoint_id'],
            $arFieldsData['payment_method'],
            $arFieldsData['test_mode']
        );
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $payment_payneteasy_test_mode = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_test_mode',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $payment_payneteasy_endpoint_id = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_endpoint_id',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $payment_payneteasy_payment_method = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_payment_method',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        $payment_payneteasy_control_key = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_control_key',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        $payment_payneteasy_cancel_status = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_cancel_status',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        $payment_payneteasy_merchant_login = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_merchant_login',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        $action_url = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_live_url',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        if ($payment_payneteasy_test_mode == true)
            $action_url = $this->_scopeConfig->getValue(
                self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_sandbox_url',
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );

        if ($order instanceof \Magento\Framework\Model\AbstractModel) {
            $payment = $order->getPayment();
            $payneteasy = $payment->getMethodInstance();

            if($order->getState() == $payment_payneteasy_cancel_status && $payneteasy->getCode() == PaynetEasy::PAYMENT_METHOD_PAYNETEASY_CODE
                && !$payment->getAdditionalInformation('payneteasy_return_sent')) {

                $_resources = \Magento\Framework\App\ObjectManager::getInstance()
                    ->get('Magento\Framework\App\ResourceConnection');
                $connection= $_resources->getConnection();
                // The refund goes to the attempt the order was paid with;
                // orders paid before this was remembered use their latest attempt
                $paynet_order_id = (string) $payment->getAdditionalInformation('payneteasy_paid_order_id');
                if ($paynet_order_id === '') {
                    $paynet_order_id = (string) $connection->fetchOne('SELECT paynet_order_id FROM payneteasy_payments WHERE merchant_order_id = ? ORDER BY id DESC LIMIT 1', [(int) $order->getId()]);
                }

                // Refund what the customer paid, in the order currency (as charged)
                $amount = (float) $order->getTotalPaid() - (float) $order->getTotalRefunded();
                if ($paynet_order_id === '' || $amount <= 0) {
                    return;
                }

                $data = [
                    'login' => $payment_payneteasy_merchant_login,
                    'client_orderid' => (string) $order->getId(),
                    'orderid' => $paynet_order_id,
                    'amount' => number_format($amount, 2, '.', ''),
                    'currency' => (string) $order->getOrderCurrencyCode(),
                    'comment' => 'Order cancel'
                ];

                $data['control'] = $this->signReturnRequest($data, $payment_payneteasy_merchant_login, $payment_payneteasy_control_key);

                // Send the return request once per order, not on every save.
                $payment->setAdditionalInformation('payneteasy_return_sent', 1);
                $payment->save();

                try {
                    $responceReturn = $this->paynetApi->return(
                        $data,
                        $payment_payneteasy_payment_method,
                        $payment_payneteasy_test_mode,
                        $action_url,
                        $payment_payneteasy_endpoint_id
                    );
                } catch (\Throwable $e) {
                    $responceReturn = ['type' => 'error', 'error-message' => $e->getMessage()];
                }

                $refused = in_array(trim((string) ($responceReturn['type'] ?? '')), ['error', 'validation-error'], true)
                    || trim((string) ($responceReturn['status'] ?? '')) === 'error';

                if (!$refused) {
                    // The refund is processed asynchronously: wait briefly for the final answer
                    for ($i = 0; $i < 5; $i++) {
                        $statusData = [
                            'login' => $payment_payneteasy_merchant_login,
                            'client_orderid' => (string) $order->getId(),
                            'orderid' => $paynet_order_id,
                        ];
                        $statusData['control'] = $this->signString($statusData['login'] . $statusData['client_orderid'] . $statusData['orderid'], $payment_payneteasy_control_key);
                        try {
                            $state = $this->paynetApi->status($statusData, $payment_payneteasy_payment_method, $payment_payneteasy_test_mode, $action_url, $payment_payneteasy_endpoint_id);
                        } catch (\Throwable $e) {
                            break;
                        }
                        $final = trim((string) ($state['status'] ?? ''));
                        if (in_array($final, ['declined', 'error', 'filtered'], true)) {
                            $refused = true;
                            $responceReturn = $state;
                            break;
                        }
                        if ($final !== 'processing') {
                            break;
                        }
                        sleep(1);
                    }
                }

                // Tell the merchant what happened to the money
                // (saved on its own: the order itself is being saved right now)
                $order->addCommentToStatusHistory($refused
                    ? __('PaynetEasy refused the refund of %1 %2: %3', $data['amount'], $data['currency'], trim((string) ($responceReturn['error-message'] ?? 'unknown error')))
                    : __('PaynetEasy refund of %1 %2 requested.', $data['amount'], $data['currency']))
                    ->save();

                $this->paynetEasyLogger->log($this, 'debug', __FUNCTION__ . ' > ', [
                    'arguments' => [
                        'orderId' => $order->getId(),
                        'responceReturn' => json_encode($responceReturn)
                    ],
                ]);
            }
        }
    }

    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }

    private function signReturnRequest($requestFields, $login, $merchantControl)
    {
        return $this->signString(
            $login . $requestFields['client_orderid'] . $requestFields['orderid'] . (int) round($requestFields['amount'] * 100) . $requestFields['currency'],
            $merchantControl
        );
    }

    private function signPaymentRequest($requestFields, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $requestFields['client_orderid'];
        $base .= $requestFields['amount'] * 100;
        $base .= $requestFields['email'];

        return $this->signString($base, $merchantControl);
    }
}