<?php
namespace Paynet\PaynetEasy\Controller\Payment;

use Exception,
    Magento\Checkout\Model\Session,
    Magento\Framework\App\Action\Action,
    Magento\Framework\App\Action\Context,
    Magento\Framework\Controller\Result\JsonFactory,
    Magento\Framework\Exception\LocalizedException,
    Magento\Framework\Message\ManagerInterface,
    Magento\Sales\Api\OrderRepositoryInterface,
    Magento\Sales\Model\Order,
    Paynet\PaynetEasy\Helper\PaynetEasyLogger;


/**
 * Class Redirect
 *
 * This class is used to handle the redirection of the customer to the Paynet payment system.
 * After a customer confirms their order and selects Paynet payment method, they are redirected to this controller.
 * This controller then fetches the order based on the checkout session and retrieves the redirect URL from the payment method.
 * The order's status is updated to pending payment, and the customer is then redirected to the Paynet payment system for payment.
 * If an error occurs, the customer's quote is restored, an error message is displayed, and the customer is redirected back to the checkout page.
 *
 * @package Paynet\PaynetEasy\Controller\Payment
 */
class Redirect extends Action
{
    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var JsonFactory
     */
    protected $jsonFactory;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var PaynetEasyLogger
     */
    protected $paynetEasyLogger;


    /**
     * Redirect constructor.
     *
     * @param Context $context
     * @param Session $checkoutSession
     * @param ManagerInterface $messageManager
     * @param JsonFactory $jsonFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param PaynetEasyLogger $paynetEasyLogger
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        ManagerInterface $messageManager,
        JsonFactory $jsonFactory,
        OrderRepositoryInterface $orderRepository,
        PaynetEasyLogger $paynetEasyLogger
    ) {
        parent::__construct($context);

        $this->checkoutSession = $checkoutSession;
        $this->messageManager = $messageManager;
        $this->jsonFactory = $jsonFactory;
        $this->orderRepository = $orderRepository;
        $this->paynetEasyLogger = $paynetEasyLogger;
    }


    /**
     * Executes when a user clicks on the "proceed to payment" button.
     * 
     * This method is responsible for handling the redirection of the customer to the Paynet payment system.
     * It fetches the order, retrieves the redirect URL from the payment method, updates the order status to pending payment,
     * and redirects the customer to the Paynet payment system. If an error occurs, the customer is redirected back to the checkout page.
     *
     * @return ResponseInterface|Json|ResultInterface
     */
    public function execute()
    {
        $session = $this->checkoutSession;
        /**
         * @var Order
         */
        $order = $session->getLastRealOrder();
        $orderId = $order->getId();

        try {
            if (empty($orderId)) {
                throw new LocalizedException(__('This order does not exist.'));
            }

            /**
             * @var PaynetEasy
             */
            $payneteasy = $order->getPayment()->getMethodInstance();
            $url = $payneteasy->getCheckoutRedirect($order);

            $order->setStatus(Order::STATE_PENDING_PAYMENT);
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $this->orderRepository->save($order);

            $result = $this->jsonFactory->create();

            return $result->setData([
                'url' => $url,
            ]);
        } catch (Exception | LocalizedException $e) {
          
            $session->restoreQuote();
            
            $this->paynetEasyLogger->log($this, 'error', sprintf(
                __FUNCTION__ . ' > PaynetEasy Exception: %s; Order id: %s',
                $e->getMessage(), 
                $orderId ?: ''
            ), [
                'code_exception' => $e->getCode(),
                'file_exception' => $e->getFile(),
                'line_exception' => $e->getLine(),
            ]);

            $this->messageManager->addErrorMessage($e->getMessage());

            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setUrl($this->_redirect->getRefererUrl());

            return $resultRedirect;
        }
    }
}