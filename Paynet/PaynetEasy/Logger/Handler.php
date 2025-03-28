<?php
namespace Paynet\PaynetEasy\Logger;

use Exception,
    Magento\Framework\App\Filesystem\DirectoryList,
    Magento\Framework\Exception\FileSystemException,
    Magento\Framework\Filesystem\DriverInterface,
    Magento\Framework\Logger\Handler\Base,
    Monolog\Logger;


/**
 * Class Handler
 * 
 * This class is responsible for handling the logging activities for the Paynet payment module.
 * It extends Magento's base logging handler and overrides the file name and logging level.
 * The log file is stored in a directory specified by the DirectoryList object and is named 'PaynetEasy-' followed by a timestamp.
 * The timestamp is generated based on the current date in the 'Y-M-d' format. 
 * The class also includes a method to check if a log file exists at the expected path.
 * 
 * @package Paynet\PaynetEasy\Logger
 */
class Handler extends Base
{
    /**
     * Logging level. 
     * Set to Logger::DEBUG which is the lowest level, logging all data.
     *
     * @var int
     */
    protected $loggerType = Logger::DEBUG;

    /**
     * Full path of the log file.
     *
     * @var string
     */
    public $filePath;


    /**
     * Handler constructor.
     * 
     * Constructs the handler object and sets the file path for the log file.
     *
     * @param DriverInterface $filesystem Provides file system operations.
     * @param DirectoryList $dir Contains constants for different directories in Magento.
     *
     * @throws FileSystemException If the directory cannot be accessed or created.
     * @throws Exception If a general error occurs.
     */
    public function __construct(
        DriverInterface $filesystem,
        DirectoryList $dir
    ) {
        $ds = DIRECTORY_SEPARATOR;
        $this->filePath = $dir->getPath('log') . $ds . 'payneteasy.log';

        parent::__construct($filesystem, $this->filePath);
    }


    /**
     * Checks if the log file exists.
     * 
     * @return bool True if the file exists, false otherwise.
     */
    public function exists()
    {
        return file_exists($this->filePath);
    }
}