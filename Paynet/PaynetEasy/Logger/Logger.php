<?php
namespace Paynet\PaynetEasy\Logger;

use Monolog\Logger as CustomLogger;

/**
 * Class Logger
 * 
 * This class extends the Monolog Logger class, which is a powerful and flexible logging library for PHP.
 * The Logger class can be used to record debug info, system information, user activities, errors, and exceptions,
 * and it can manage records by different severity levels such as DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY.
 * It currently does not contain any additional functionality compared to the parent class, 
 * but it can be expanded in the future to provide custom logging behavior specific to the Paynet payment module.
 * 
 * @package Paynet\PaynetEasy\Logger
 */
class Logger extends CustomLogger
{
}