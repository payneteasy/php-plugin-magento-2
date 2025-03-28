<?php
/**
 * This is the registration file for the 'Paynet_PaynetEasy' module.
 *
 * The purpose of this file is to use the ComponentRegistrar class to register the 'Paynet_PaynetEasy' module
 * with Magento 2's component-based architecture. ComponentRegistrar is a class that Magento 2 uses
 * for module discovery. This means when Magento 2 is compiling its list of modules, it uses the 
 * ComponentRegistrar class to find all the modules in the system.
 *
 * @category   Paynet
 * @package    Paynet
 * @author     Payneteasy <info@payneteasy.com>
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Paynet_PaynetEasy',
    __DIR__
);
