<?php
namespace Paynet\PaynetEasy\Model;

use Exception,
    Magento\Payment\Model\Method\AbstractMethod,
    Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface,
    Magento\Sales\Model\Order,
    Magento\Framework\App\Config\ScopeConfigInterface,
    Magento\Quote\Api\Data\CartInterface,
    Magento\Framework\Exception\LocalizedException,
    Magento\Framework\Model\Context,
    Magento\Framework\Registry,
    Magento\Framework\Api\ExtensionAttributesFactory,
    Magento\Framework\Api\AttributeValueFactory,
    Magento\Payment\Helper\Data,
    Magento\Payment\Model\Method\Logger,
    Magento\Checkout\Model\Session,
    Magento\Sales\Model\OrderFactory,
    Magento\Framework\UrlInterface,
    Magento\Framework\App\ResourceConnection,
    Paynet\PaynetEasy\Gateway\Http\Client\PaynetApi,
    Paynet\PaynetEasy\Helper\PaynetEasyLogger;


/**
 * PaynetEasy Payment Method
 * 
 * This class extends Magento's AbstractMethod to provide PaynetEasy as a payment method.
 * It defines several properties and methods required by the Magento payment method system, including code, form block type,
 * and info block type.
 * It also interacts with the PaynetApi to get and provide order information to and from the PaynetEasy system.
 *
 * @package Paynet\PaynetEasy\Model
 */
class PaynetEasy extends AbstractMethod
{
    /**
     * These boolean variables define the capabilities of the PaynetEasy payment method.
     * It declares the method as a gateway method, capable of order, authorize, capture, and use for internal transactions
     * and checkout, but not capable of refunds or multi-shipping transactions.
     */
    protected $_isGateway = true;
    protected $_isOffline = false;
    protected $_canOrder = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = true;
    protected $_isInitializeNeeded = true;

    /**
     * @var string Payment method code and XML path for PaynetEasy configuration data.
     */
    public const PAYMENT_METHOD_PAYNETEASY_CODE = 'paynet_payneteasy';
    public const PAYMENT_METHOD_PAYNETEASY_XML_PATH = 'payment/paynet_payneteasy/';

    /**
     * @var string Payment method code for this module.
     */
    protected $_code = self::PAYMENT_METHOD_PAYNETEASY_CODE;

    /**
     * @var string Path to the template for the PaynetEasy payment information block.
     */
    protected $_infoBlockType = \Magento\Payment\Block\Info\Instructions::class;

    /**
     * @var \Paynet\PaynetEasy\Model\PaynetApi Reference to the PaynetApi object that performs interactions with the PaynetEasy system.
     */
    protected $paynetApi;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var BuilderInterface
     */
    protected $transactionBuilder;

    /**
     * @var UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @var PaynetEasyLogger
     */
    protected $paynetEasyLogger;

    protected $_scopeConfig;

    /**
     * The constructor sets up the necessary dependencies for the class.
     *
     * @param Context $context Application context object
     * @param Registry $registry Registry object
     * @param ExtensionAttributesFactory $extensionFactory Extension attributes factory object
     * @param AttributeValueFactory $customAttributeFactory Custom attribute factory object
     * @param Data $paymentData Payment helper object
     * @param ScopeConfigInterface $scopeConfig Scope configuration object
     * @param Logger $logger Logger object
     * @param Session $checkoutSession Checkout session object
     * @param OrderFactory $orderFactory Order factory object
     * @param BuilderInterface $transactionBuilder Transaction builder object
     * @param UrlInterface $urlBuilder URL builder object
     * @param PaynetEasyLogger $paynetEasyLogger Custom PaynetEasy logger object
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        Session $checkoutSession,
        OrderFactory $orderFactory,
        BuilderInterface $transactionBuilder,
        UrlInterface $urlBuilder,
        PaynetEasyLogger $paynetEasyLogger
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_orderFactory = $orderFactory;
        $this->_transactionBuilder = $transactionBuilder;
        $this->_urlBuilder = $urlBuilder;
        $this->paynetEasyLogger = $paynetEasyLogger;
        $this->_scopeConfig = $scopeConfig;

        $this->setPaynetApi($scopeConfig);

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger
        );
    }


    /**
     * setPaynetApi() initializes the PaynetApi object with configuration data.
     *
     * @param ScopeConfigInterface $scopeConfig Scope configuration object
     */
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


    /**
     * initialize() method sets the initial order status and state.
     *
     * @param string $paymentAction Payment action string
     * @param \Magento\Framework\DataObject $stateObject State object
     * @return PaymentMethod Returns this class instance.
     */
    public function initialize($paymentAction, $stateObject): PaymentMethod
    {
        $stateObject->setStatus('new');
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * getCheckoutRedirect() method requests a checkout URL from the PaynetApi
     * and returns it, throwing an exception if no URL is received.
     *
     * @param Order $order Order object
     * @return string Returns checkout URL string.
     * @throws LocalizedException Throws exception when failed to get payment URL.
     */
    public function getCheckoutRedirect($order)
    {
        $orderId = $order->getId();

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

        $action_url = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_live_url',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        if ($payment_payneteasy_test_mode == true)
            $action_url = $this->_scopeConfig->getValue(
                self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_sandbox_url',
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );

        $email = $order->getCustomerEmail();
        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();
        $street = $shippingAddress->getStreet()?:$billingAddress->getStreet();
        $city = $shippingAddress->getCity()?:$billingAddress->getCity();
        $phone = $shippingAddress->getTelephone()?:$billingAddress->getTelephone();
        $postcode = $shippingAddress->getPostcode()?:$billingAddress->getPostcode();
        $getShippingAddress = $order->getShippingAddress()->getData();
        $countryCode = $getShippingAddress['country_id'];
        $payment = $order->getPayment();
        $additionalInformation = $payment->getAdditionalInformation();

        if (isset($additionalInformation['additional_data'])) {
            $credit_card_number = '';
            if (isset($additionalInformation['additional_data']['credit_card_number'])) {
                $credit_card_number = $additionalInformation['additional_data']['credit_card_number'];
            }
            $card_printed_name = '';
            if (isset($additionalInformation['additional_data']['card_printed_name'])) {
                $card_printed_name = $additionalInformation['additional_data']['card_printed_name'];
            }
            $expire_month = '';
            if (isset($additionalInformation['additional_data']['expire_month'])) {
                $expire_month = $additionalInformation['additional_data']['expire_month'];
            }
            $expire_year = '';
            if (isset($additionalInformation['additional_data']['expire_year'])) {
                $expire_year = $additionalInformation['additional_data']['expire_year'];
            }
            $cvv2 = '';
            if (isset($additionalInformation['additional_data']['cvv2'])) {
                $cvv2 = $additionalInformation['additional_data']['cvv2'];
            }
            $card_data = [
                'credit_card_number' => $credit_card_number?:'',
                'card_printed_name' => $card_printed_name?:'',
                'expire_month' => $expire_month?:'',
                'expire_year' => $expire_year?:'',
                'cvv2' => $cvv2?:'',
            ];
        } else {
            $card_data = [
                'credit_card_number' => '',
                'card_printed_name' => '',
                'expire_month' => '',
                'expire_year' => '',
                'cvv2' => '',
            ];
        }
            

        $data = [
            'client_orderid' => (string)$orderId,
            'order_desc' => 'Order # ' . $orderId,
            'amount' => $order->getGrandTotal(),
            'currency' => $order->getOrderCurrencyCode()?:'',
            'address1' => $street[0],
            'city' => $city,
            'zip_code' => $postcode,
            'country' => $countryCode,
            'phone'      => $phone,
            'email'      => $email,
            'ipaddress' => $_SERVER['REMOTE_ADDR'],
            'cvv2' => $card_data['cvv2'],
            'credit_card_number' => $card_data['credit_card_number'],
            'card_printed_name' => $card_data['card_printed_name'],
            'expire_month' => $card_data['expire_month'],
            'expire_year' => $card_data['expire_year'],
            'first_name' => $order->getCustomerFirstname(),
            'last_name'  => $order->getCustomerLastname(),
            'redirect_success_url'      => $this->getReturnUrl() . '?orderId=' . $orderId,
            'redirect_fail_url'      => $this->getReturnUrl() . '?orderId=' . $orderId,
            'redirect_url' => $this->getReturnUrl() . '?orderId=' . $orderId,
            'server_callback_url' => $this->getReturnUrl() . '?orderId=' . $orderId,
        ];

        $data['control'] = $this->signPaymentRequest($data, $payment_payneteasy_endpoint_id, $payment_payneteasy_control_key);

        if ($payment_payneteasy_payment_method == 'form') {
            $response = $this->paynetApi->saleForm(
                $data,
                $payment_payneteasy_payment_method,
                $payment_payneteasy_test_mode,
                $action_url,
                $payment_payneteasy_endpoint_id
            );
        } elseif ($payment_payneteasy_payment_method == 'direct') {
            $response = $this->paynetApi->saleDirect(
                $data,
                $payment_payneteasy_payment_method,
                $payment_payneteasy_test_mode,
                $action_url,
                $payment_payneteasy_endpoint_id
            );
        }

        $this->paynetEasyLogger->log($this, 'debug', __FUNCTION__ . ' > getOrderLink', [
            'arguments' => [
                'orderId' => $orderId,
                'email' => $email,
                'time' => time(),
                'total' => $order->getGrandTotal()
            ],
            'response' => $response
        ]);
        $_resources = \Magento\Framework\App\ObjectManager::getInstance()
            ->get('Magento\Framework\App\ResourceConnection');
        $connection= $_resources->getConnection();
        $tableName = $connection->getTableName('payneteasy_payments');
        $insertData = [
            'paynet_order_id' => $response['paynet-order-id'],
            'merchant_order_id' => $response['merchant-order-id'],
        ];

        $connection->insert($tableName, $insertData);

        if (
            !isset($response['redirect-url']) ||
            empty($payUrl = $response['redirect-url'])
        ) {
//            throw new LocalizedException(__('Failed to get payment URL.'));
            return $this->getReturnUrl() . '?orderId=' . $orderId;
        } else {
            return $response['redirect-url'];
        }


    }

    private function signPaymentRequest($data, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $data['client_orderid'];
        $base .= $data['amount'] * 100;
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }

    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }

    /**
     * getReturnUrl() method returns the URL that the PaynetEasy gateway should redirect
     * the customer to after they complete their payment.
     *
     * @return string Returns URL string.
     */
    protected function getReturnUrl()
    {
        // You might need to adjust the exact URL depending on your module structure.
        return $this->_urlBuilder->getUrl('payneteasy/payment/handleResponse', ['_secure' => true]);
    }


    /**
     * getPaymentStatus() method requests the payment status from the PaynetApi
     * for a specific order and returns it, throwing an exception if no status is received.
     *
     * @param Order $order Order object
     * @return string Returns payment status string.
     * @throws LocalizedException Throws exception when no information about payment status.
     */
    public function getPaymentStatus($order)
    {
        $orderId = $order->getId();
        $payment_payneteasy_merchant_login = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_merchant_login',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $payment_payneteasy_endpoint_id = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_endpoint_id',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        $payment_payneteasy_test_mode = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_test_mode',
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
        $action_url = $this->_scopeConfig->getValue(
            self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_live_url',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        if ($payment_payneteasy_test_mode == true)
            $action_url = $this->_scopeConfig->getValue(
                self::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_sandbox_url',
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );

        $_resources = \Magento\Framework\App\ObjectManager::getInstance()
            ->get('Magento\Framework\App\ResourceConnection');
        $connection= $_resources->getConnection();
        $paynet_order_id = $connection->fetchAll('SELECT paynet_order_id FROM payneteasy_payments WHERE merchant_order_id = ' . $orderId);

        $data = [
            'login' => $payment_payneteasy_merchant_login,
            'client_orderid' => (string)$orderId,
            'orderid' => $paynet_order_id[0]['paynet_order_id'],
        ];

        $data['control'] = $this->signStatusRequest($data, $payment_payneteasy_merchant_login, $payment_payneteasy_control_key);

        $response = $this->paynetApi->status($data, $payment_payneteasy_payment_method, $payment_payneteasy_test_mode, $action_url, $payment_payneteasy_endpoint_id);
        
        $this->paynetEasyLogger->log($this, 'debug', __FUNCTION__ . ' > getOrderInfo', [
            'arguments' => [
                'orderId' => $paynet_order_id[0]['paynet_order_id'],
                'client_orderid' => $orderId,
                'status' => $response['status']
            ],
            'response' => $response
        ]);

        if (
            !isset($response['status'])
        ) {
            throw new LocalizedException(__('No information about payment status.'));
        }

        return $response;
    }

    private function signStatusRequest($requestFields, $login, $merchantControl)
    {
        $base = '';
        $base .= $login;
        $base .= $requestFields['client_orderid'];
        $base .= $requestFields['orderid'];

        return $this->signString($base, $merchantControl);
    }


    /**
     * isAvailable() method checks if the payment method is available for use
     * based on whether or not it is active in the configuration.
     *
     * @param CartInterface|null $quote Cart quote object
     * @return bool Returns true if the payment method is active, otherwise false.
     */
    public function isAvailable(CartInterface $quote = null): bool
    {
        if (!$this->isActive($quote ? $quote->getStoreId() : null)) {
            return false;
        }
        return true;
    }


    /**
     * getInstructions() method fetches the instruction text configured for the payment method.
     *
     * @return string Returns instruction text string.
     */
    public function getInstructions()
    {
        $instructions = $this->getConfigData('instructions');
        return !empty($instructions) ? trim($instructions) : '';
    }

    public function isDirectMethod()
    {
        return $this->getConfigData('payment_payneteasy_payment_method');
    }

    public function isTestMode()
    {
        return $this->getConfigData('payment_payneteasy_test_mode');
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);
        $this->getInfoInstance()->setAdditionalInformation($data->getData());

        return $this;
    }
}