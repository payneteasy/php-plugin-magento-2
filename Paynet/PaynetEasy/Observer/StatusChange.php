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

            if($order->getState() == $payment_payneteasy_cancel_status && $payneteasy->getCode() == PaynetEasy::PAYMENT_METHOD_PAYNETEASY_CODE) {

                $_resources = \Magento\Framework\App\ObjectManager::getInstance()
                    ->get('Magento\Framework\App\ResourceConnection');
                $connection= $_resources->getConnection();
                $paynet_order_id = $connection->fetchAll('SELECT paynet_order_id FROM payneteasy_payments WHERE merchant_order_id = ' . $order->getId());

                $data = [
                    'login' => $payment_payneteasy_merchant_login,
                    'client_orderid' => $order->getId(),
                    'orderid' => $paynet_order_id[0]['paynet_order_id'],
                    'comment' => 'Order cancel '
                ];

                $data['control'] = $this->signPaymentRequest($data, $payment_payneteasy_endpoint_id, $payment_payneteasy_control_key);

                $responceReturn = $this->paynetApi->return(
                    $data,
                    $payment_payneteasy_payment_method,
                    $payment_payneteasy_test_mode,
                    $action_url,
                    $payment_payneteasy_endpoint_id
                );
                
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