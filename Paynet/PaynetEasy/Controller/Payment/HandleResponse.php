<?php
namespace Paynet\PaynetEasy\Controller\Payment;

use Exception,
    Magento\Framework\App\Action\Action,
    Magento\Framework\App\Action\Context,
    Magento\Framework\App\Config\ScopeConfigInterface,
    Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilder,
    Magento\Customer\Model\Session as CustomerSession,
    Magento\Sales\Api\OrderRepositoryInterface,
    Magento\Framework\Exception\LocalizedException,
    Magento\Sales\Model\Order\Payment\Transaction,
    Magento\Sales\Model\Order,
    Paynet\PaynetEasy\Model\PaynetEasy,
    Paynet\PaynetEasy\Helper\PaynetEasyLogger;


/**
 * Class HandleResponse
 *
 * This class is used to handle the response from the Paynet payment system.
 * After a customer makes a payment, the payment system redirects the customer to this controller.
 * This controller fetches the order based on the request or the checkout session and checks the payment status.
 * If the payment is successful, it updates the order status, creates a transaction and redirects the customer to the order view page.
 * If the payment fails, it redirects the customer back to the checkout page and displays an error message.
 *
 * @package Paynet\PaynetEasy\Controller\Payment
 */
class HandleResponse extends Action
{

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    
    /**
     * @var TransactionBuilder
     */
    protected $transactionBuilder;
    
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;
    
    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var PaynetEasyLogger
     */
    protected $paynetEasyLogger;
    

    /**
     * HandleResponse constructor.
     *
     * @param Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param TransactionBuilder $transactionBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param CustomerSession $customerSession
     * @param PaynetEasyLogger $paynetEasyLogger
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        TransactionBuilder $transactionBuilder,
        OrderRepositoryInterface $orderRepository,
        CustomerSession $customerSession,
        PaynetEasyLogger $paynetEasyLogger
    ) {
        parent::__construct($context);
        
        $this->scopeConfig = $scopeConfig;
        $this->transactionBuilder = $transactionBuilder;
        $this->orderRepository = $orderRepository;
        $this->customerSession = $customerSession;
        $this->paynetEasyLogger = $paynetEasyLogger;
    }


    /**
     * Main execution method.
     *
     * This method is responsible for handling the response from the Paynet payment system.
     * It fetches the order, checks the payment status, updates the order status, creates a transaction and redirects
     * the customer to the correct page based on the outcome of the payment.
     *
     * @return \Magento\Framework\Controller\Result\Redirect|\Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        // Get order ID from request params, or from session
        $orderId = $this->getRequest()->getParam('orderId');
        if (!$orderId)
            $orderId = $this->getRequest()->getParam('client_orderid');



        try {
            if (empty($orderId)) {
                throw new LocalizedException(__('Order ID not found.'));
            }

            // Load the order
            $order = $this->orderRepository->get($orderId);
            if (!$order->getId()) {
                throw new LocalizedException(__('This order does not exist.'));
            }

            /**
             * @var PaynetEasy
             */
            $payment = $order->getPayment();
            $payneteasy = $payment->getMethodInstance();
            if ($payneteasy->getCode() !== PaynetEasy::PAYMENT_METHOD_PAYNETEASY_CODE) {
                throw new LocalizedException(__('Unknown payment method.'));
            }

            $response = $payneteasy->getPaymentStatus($order);

            $this->paynetEasyLogger->log($this, 'debug', __FUNCTION__ . ' > execute', [
                'arguments' => [
                    'orderId' => $orderId,
                    'status' => $response['status']
                ],
            ]);

            $payment_payneteasy_three_d_secure = $this->scopeConfig->getValue(
                PaynetEasy::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_three_d_secure',
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );

            if ($payment_payneteasy_three_d_secure == true) {
                $this->paynetEasyLogger->log($this, 'debug', __FUNCTION__ . ' > execute', [
                    'arguments' => [
                        'orderId' => $orderId,
                        '$response' => json_encode($response)
                    ],
                ]);
                if (isset($response['html']))
                echo $response['html'];
            }

            if (trim($response['status']) == 'declined' || trim($response['status']) == 'error') {
                throw new LocalizedException(__('Payment status is not Approved.'));
            } elseif (trim($response['status']) == 'approved'){
                $endStatus = $this->scopeConfig->getValue(
                    PaynetEasy::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_transaction_end_status',
                    ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                );
                // Установка оплаченной суммы и статуса заказа
                $order->setTotalPaid($order->getGrandTotal());
                $order->setBaseTotalPaid($order->getBaseGrandTotal());
                $order->setState(Order::STATE_COMPLETE)
                    ->setStatus($endStatus);

                $this->createTransaction($order, $payment);

                $this->orderRepository->save($order);

                $this->messageManager->addSuccessMessage(__('Payment was successful.') . ' Order # ' . $order->getIncrementId());

                // Redirect to the order view page
                if ($this->customerSession->isLoggedIn()) {
                    $pathRedirect = 'sales/order/view/order_id/' . $order->getId();
                } else {
                    $pathRedirect = 'sales/guest/form/';
                }
                $resultRedirect = $this->resultRedirectFactory->create();
                $resultRedirect->setPath($pathRedirect);
            }
        } catch (Exception | LocalizedException $e) {

            $this->paynetEasyLogger->log($this, 'error', sprintf(
                __FUNCTION__ . ' > PaynetEasy Exception: %s; Order id: %s',
                $e->getMessage(),
                $orderId ?: ''
            ), [
                'code_exception' => $e->getCode(),
                'file_exception' => $e->getFile(),
                'line_exception' => $e->getLine(),
            ]);

            $this->messageManager->addErrorMessage(__('Payment failed.') . ' ' . $e->getMessage());

            $pathRedirect = 'checkout/cart';
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath($pathRedirect);
        }
        $payment_payneteasy_three_d_secure = $this->scopeConfig->getValue(
            PaynetEasy::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_three_d_secure',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        if ($payment_payneteasy_three_d_secure) {
            $resultRedirect = false;
        }
        return $resultRedirect;
    }


    /**
     * Creates a capture type transaction for the given order and payment.
     *
     * @param Order $order The order for which the transaction is to be created.
     * @param \Magento\Payment\Model\InfoInterface $payment The payment info instance.
     * 
     * @return void
     */
    private function createTransaction($order, $payment)
    {
        $transaction = $this->transactionBuilder
            ->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($order->getIncrementId())
            ->setAdditionalInformation(
                [Transaction::RAW_DETAILS => (array)$payment->getAdditionalInformation()]
            )
            ->setFailSafe(true)
            ->build(Transaction::TYPE_CAPTURE);
            
        $payment->addTransactionCommentsToOrder(
            $transaction,
            __('The payment transaction has been captured.')
        );
    }
}
