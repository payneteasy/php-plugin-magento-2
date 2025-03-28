<?php
namespace Paynet\PaynetEasy\Helper;

use Magento\Framework\Serialize\Serializer\Json,
    Magento\Framework\App\Config\ScopeConfigInterface,
    Paynet\PaynetEasy\Model\PaynetEasy,
    Paynet\PaynetEasy\Logger\Logger;


/**
 * Class PaynetEasyLogger
 *
 * This class provides a logging mechanism for the Paynet payment module.
 * It checks if the logging is enabled via configuration in the admin panel and logs the events accordingly.
 * The class uses the custom Logger object to perform the actual logging operation.
 * The logging message is constructed with the provided class name, message, and context information.
 *
 * @package Paynet\PaynetEasy\Helper
 */
class PaynetEasyLogger
{
    /**
     * @var Logger
     */
    protected $_logger;
    
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Json
     */
    protected $_json;


    /**
     * PaynetEasyLogger constructor.
     * 
     * @param Logger $logger
     * @param Json $json
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
      Logger $logger, 
      Json $json,
      ScopeConfigInterface $scopeConfig
    ) {
        $this->_logger = $logger;
        $this->_json = $json;
        $this->scopeConfig = $scopeConfig;
    }
    
    
    /**
     * Logs the provided message if logging is enabled in configuration.
     *
     * The log type (e.g., info, warning, error) is determined by the $type parameter.
     * The message is constructed with the provided class name, message, and context information.
     * 
     * @param object $class The object from which the log originated.
     * @param string $type The log type (info, warning, error, etc.).
     * @param string $message The log message.
     * @param array $context Additional information to be included in the log.
     * 
     * @return void|false Returns false if logging is disabled, otherwise returns void.
     */
    public function log(object $class, string $type, string $message, array $context = [])
    {
        if (empty($message) || $this->isLoggingNotAvailable($type)) {
            return null;
        }
        
        if (!empty($context) && is_array($context)) {
            $message .= ' Context: '. PHP_EOL . $this->_json->serialize($context);
        }
        
        $this->_logger->$type(get_class($class) . ' - ' . $message);
    }
    

    /**
     * Checks that logging is not enabled in the configuration 
     * and that the logging type is not an error.
     * 
     * @param string $type The log type (info, warning, error, etc.).
     * 
     * @return bool True if logging is enabled, false otherwise.
     */
    protected function isLoggingNotAvailable(string $type)
    {
        $logging = $this->scopeConfig->getValue(
            PaynetEasy::PAYMENT_METHOD_PAYNETEASY_XML_PATH . 'payment_payneteasy_logging',
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        
        return ($type !== 'error' && $logging !== '1');
    }
}