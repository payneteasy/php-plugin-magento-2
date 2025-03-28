<?php
namespace Paynet\PaynetEasy\Model;

use Magento\Checkout\Model\ConfigProviderInterface,
    Magento\Framework\Escaper,
    Magento\Payment\Helper\Data as PaymentHelper;


/**
 * Class InstructionsConfigProvider
 * 
 * This class implements the ConfigProviderInterface from the Magento\Checkout module.
 * It's designed to provide additional configuration for the checkout module, specifically instructions related to PaynetEasy payment method.
 * It includes payment instruction details for a set of defined payment method codes and delivers them to the checkout configuration.
 *
 * @package Paynet\PaynetEasy\Model
 */
class InstructionsConfigProvider implements ConfigProviderInterface
{
    /**
     * @var string[] Array of payment method codes supported by this module.
     */
    protected $methodCodes = [
        PaynetEasy::PAYMENT_METHOD_PAYNETEASY_CODE
    ];

    /**
     * @var \Magento\Payment\Model\Method\AbstractMethod[] Array of payment method instances.
     */
    protected $methods = [];

    /**
     * @var Escaper Service class used for encoding data for output in HTML.
     */
    protected $escaper;
    

    /**
     * Constructor method for the class. It initializes the Escaper object and payment method instances.
     *
     * @param PaymentHelper $paymentHelper An object of the PaymentHelper, which provides various methods to deal with payment configurations.
     * @param Escaper $escaper An object of the Escaper, a service class used for encoding data for output in HTML.
     */
    public function __construct(
        PaymentHelper $paymentHelper,
        Escaper $escaper
    ) {
        $this->escaper = $escaper;
        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $paymentHelper->getMethodInstance($code);
        }
    }
    

    /**
     * Implements getConfig() method of the ConfigProviderInterface.
     * It is used to provide additional configuration to the checkout module.
     * The method iterates over the list of payment method codes and if a payment method is available,
     * its instructions are added to the configuration.
     *
     * @return array Returns configuration data array with payment instructions.
     */
    public function getConfig()
    {
        $config = [];
        foreach ($this->methodCodes as $code) {
            if ($this->methods[$code]->isAvailable()) {
                $config['payment']['instructions'][$code] = $this->getInstructions($code);
                $config['payment']['payment_payneteasy_payment_method'][$code] = $this->methods[$code]->isDirectMethod();
                $config['payment']['payment_payneteasy_test_mode'][$code] = $this->methods[$code]->isTestMode();
            }
        }
        return $config;
    }

    /**
     * Get payment instructions from the payment method configuration.
     * 
     * @param string $code The code of the payment method.
     * @return string Returns a string of instructions for the provided payment method code.
     */
    protected function getInstructions($code)
    {
        return nl2br($this->escaper->escapeHtml($this->methods[$code]->getInstructions()));
    }
}
