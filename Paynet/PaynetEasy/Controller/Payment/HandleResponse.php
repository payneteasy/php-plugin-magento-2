<?php
namespace Paynet\PaynetEasy\Controller\Payment;

use Exception,
    Magento\Framework\App\Action\Action,
    Magento\Framework\App\Action\Context,
    Magento\Framework\App\Config\ScopeConfigInterface,
    Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface as TransactionBuilder,
    Magento\Customer\Model\Session as CustomerSession,
    Magento\Checkout\Model\Session as CheckoutSession,
    Magento\Sales\Api\OrderRepositoryInterface,
    Magento\Framework\Exception\LocalizedException,
    Magento\Sales\Model\Order\Payment\Transaction,
    Magento\Sales\Model\Order,
    Paynet\PaynetEasy\Model\PaynetEasy,
    Paynet\PaynetEasy\Model\OrderLock,
    Paynet\PaynetEasy\Helper\PaynetEasyLogger,
    Magento\Framework\Controller\ResultFactory,
    Magento\Framework\App\CsrfAwareActionInterface,
    Magento\Framework\App\Request\InvalidRequestException,
    Magento\Framework\App\RequestInterface;


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
class HandleResponse extends Action implements CsrfAwareActionInterface
{
    /**
     * The gateway returns the customer with a POST from its own site, which has
     * no form key. The request data is not trusted: the payment status is
     * always requested from the gateway.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }


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
     * @var CheckoutSession
     */
    protected $checkoutSession;
    

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
        PaynetEasyLogger $paynetEasyLogger,
        CheckoutSession $checkoutSession
    ) {
        parent::__construct($context);
        
        $this->scopeConfig = $scopeConfig;
        $this->transactionBuilder = $transactionBuilder;
        $this->orderRepository = $orderRepository;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
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

        // The result is shown only to the customer of the order. Anyone else
        // (the gateway callback too) gets the same answer for every order.
        $isCustomer = false;
        $result = null;

        $orderLock = $this->_objectManager->get(OrderLock::class);
        $locked = false;

        try {
            if (empty($orderId) || !is_scalar($orderId) || !ctype_digit((string) $orderId)) {
                $orderId = '';
                throw new LocalizedException(__('Order ID not found.'));
            }

            // The customer return and the callback may come together:
            // they are handled one after another
            $locked = $orderLock->lock($orderId);

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

            $isCustomer = $this->isCustomerOfOrder($order, $payneteasy);

            $alreadyPaid = (float) $order->getTotalPaid() >= (float) $order->getGrandTotal() - 0.0001;

            if ($alreadyPaid) {
                // A paid order needs no request to the gateway
                $response = ['status' => 'approved'];
            } elseif ($payment->getAdditionalInformation('payneteasy_paid_after_cancel')) {
                throw new LocalizedException(__('The order was canceled before the payment was received. Please contact the store.'));
            } elseif (!$locked || !$orderLock->allowStatusRequest($orderId)) {
                // Too many requests for this order: the gateway is asked later
                $response = ['status' => 'processing'];
            } else {
                $response = $payneteasy->getPaymentStatus($order);
            }

            $status = trim((string) $response['status']);

            $this->paynetEasyLogger->log($this, 'debug', __FUNCTION__ . ' > execute', [
                'arguments' => [
                    'orderId' => $orderId,
                    'status' => $status
                ],
            ]);

            if (in_array($status, ['declined', 'error', 'filtered'], true)) {
                $this->cancelDeclinedOrder($order);
                throw new LocalizedException(__('Payment status is not Approved.'));
            } elseif ($status == 'approved') {
                if (!$alreadyPaid && $order->getState() === Order::STATE_CANCELED) {
                    // The money was taken for an order that is canceled already:
                    // the merchant is told once, the customer sees no success
                    $payment->setAdditionalInformation('payneteasy_paid_after_cancel', 1);
                    $payment->save();
                    $order->addCommentToStatusHistory(__(
                        'PaynetEasy: the payment %1 was approved after the order had been canceled. The order is not marked as paid: check the payment and refund it.',
                        (string) ($response['attempt-paynet-order-id'] ?? '')
                    ))->save();

                    throw new LocalizedException(__('The order was canceled before the payment was received. Please contact the store.'));
                }

                $endStatus = $this->scopeConfig->getValue(
                    PaynetEasy::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_transaction_end_status',
                    ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                );
                // Mark the order paid once: a repeated return or callback, or an
                // order already closed, is left unchanged.
                $finalState = in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED, Order::STATE_CANCELED], true);

                if (!$alreadyPaid && !$finalState) {
                    // Set the paid amount and the order status
                    $order->setTotalPaid($order->getGrandTotal());
                    $order->setBaseTotalPaid($order->getBaseGrandTotal());
                    $order->setState(Order::STATE_COMPLETE)
                        ->setStatus($endStatus);

                    // The paid attempt: a refund is sent for it
                    if (!empty($response['attempt-paynet-order-id'])) {
                        $payment->setAdditionalInformation('payneteasy_paid_order_id', (string) $response['attempt-paynet-order-id']);
                    }

                    $this->createTransaction($order, $payment);

                    $this->orderRepository->save($order);
                }

                if ($isCustomer) {
                    $this->messageManager->addSuccessMessage(__('Payment was successful.') . ' Order # ' . $order->getIncrementId());

                    // Redirect to the order view page
                    if ($this->customerSession->isLoggedIn()) {
                        $pathRedirect = 'sales/order/view/order_id/' . $order->getId();
                    } else {
                        $pathRedirect = 'sales/guest/form/';
                    }
                    $result = $this->resultRedirectFactory->create();
                    $result->setPath($pathRedirect);
                }
            } elseif ($isCustomer) {
                $payment_payneteasy_three_d_secure = $this->scopeConfig->getValue(
                    PaynetEasy::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_three_d_secure',
                    ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                );

                if ($payment_payneteasy_three_d_secure == true && isset($response['html']) && trim((string) $response['html']) !== '') {
                    // 3-D Secure: the page of the gateway sends the customer to the bank
                    $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
                    $result->setContents($response['html']);
                } else {
                    // The payment is not finished yet: the page asks again in a few seconds
                    $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
                    $result->setContents($this->getWaitPage($payneteasy->getCustomerReturnUrl($order)));
                }
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

            $result = null;

            if ($isCustomer) {
                // Only a message meant for the customer is shown, never a technical one
                $this->messageManager->addErrorMessage(__('Payment failed.') . ' ' . ($e instanceof LocalizedException
                    ? $e->getMessage()
                    : __('Please try again later.')));

                $result = $this->resultRedirectFactory->create();
                $result->setPath('checkout/cart');
            }
        } finally {
            if ($locked) {
                $orderLock->unlock($orderId);
            }
        }

        if ($result === null) {
            // Not the customer of the order: nothing about the order is told
            $result = $this->resultRedirectFactory->create();
            $result->setPath('');
        }

        return $result;
    }


    /**
     * True if the visitor is the customer of the order: he came back from the
     * gateway with the key of the order, has just placed it in this session,
     * or is logged in as its customer.
     *
     * @param Order $order
     * @param PaynetEasy $payneteasy
     *
     * @return bool
     */
    private function isCustomerOfOrder($order, $payneteasy)
    {
        $key = $this->getRequest()->getParam('key');
        if (is_string($key) && $key !== '' && hash_equals($payneteasy->getReturnKey($order), $key)) {
            return true;
        }

        $lastOrder = $this->checkoutSession->getLastRealOrder();
        if ($lastOrder && $lastOrder->getId() && $lastOrder->getId() == $order->getId()) {
            return true;
        }

        return $this->customerSession->isLoggedIn()
            && $order->getCustomerId()
            && $order->getCustomerId() == $this->customerSession->getCustomerId();
    }


    /**
     * Page for a payment that is still processing: it opens the result address again.
     *
     * @param string $url
     *
     * @return string
     */
    private function getWaitPage($url)
    {
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars((string) __('Please wait, your payment is being processed.'), ENT_QUOTES, 'UTF-8');
        $link = htmlspecialchars((string) __('Check the result'), ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex">'
            . '<meta http-equiv="refresh" content="5;url=' . $url . '">'
            . '<title>' . $text . '</title></head>'
            . '<body><p>' . $text . '</p><p><a href="' . $url . '">' . $link . '</a></p></body></html>';
    }


    /**
     * Cancels an unpaid order after a final decline and returns its items to
     * the cart, so that the customer can pay again with a new order.
     *
     * @param Order $order
     *
     * @return void
     */
    private function cancelDeclinedOrder($order)
    {
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT || (float) $order->getTotalPaid() > 0) {
            return;
        }

        if ($order->canCancel()) {
            $order->cancel();
            $order->addCommentToStatusHistory(__('PaynetEasy: payment declined.'));
            $this->orderRepository->save($order);
        }

        $lastOrder = $this->checkoutSession->getLastRealOrder();
        if ($lastOrder && $lastOrder->getId() == $order->getId()) {
            $this->checkoutSession->restoreQuote();
        }
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
                [Transaction::RAW_DETAILS => ['method' => $payment->getMethod()]]
            )
            ->setFailSafe(true)
            ->build(Transaction::TYPE_CAPTURE);
            
        $payment->addTransactionCommentsToOrder(
            $transaction,
            __('The payment transaction has been captured.')
        );
    }
}
