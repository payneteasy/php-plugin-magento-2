<?php
namespace Paynet\PaynetEasy\Model;

use Magento\Framework\App\CacheInterface,
    Magento\Framework\App\ObjectManager,
    Magento\Framework\App\ResourceConnection;


/**
 * Per-order lock and limit of gateway status requests.
 *
 * The customer return, the gateway callback and a repeated payment start may
 * come together: with the lock they are handled one after another.
 *
 * @package Paynet\PaynetEasy\Model
 */
class OrderLock
{
    /**
     * Seconds to wait for the lock of an order
     */
    const LOCK_TIMEOUT = 30;

    /**
     * Gateway status requests allowed for one order within STATUS_PERIOD seconds
     */
    const STATUS_LIMIT = 20;
    const STATUS_PERIOD = 60;

    /**
     * Takes the lock of the order. The lock belongs to the database connection:
     * it is released by unlock() or when the connection is closed.
     *
     * @param int $orderId
     * @return bool False if the lock was not received in time
     */
    public function lock($orderId)
    {
        return (bool) $this->getConnection()->fetchOne(
            'SELECT GET_LOCK(?, ?)',
            [$this->getLockName($orderId), self::LOCK_TIMEOUT]
        );
    }

    /**
     * Releases the lock of the order.
     *
     * @param int $orderId
     * @return void
     */
    public function unlock($orderId)
    {
        try {
            $this->getConnection()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->getLockName($orderId)]);
        } catch (\Throwable $e) {
            // The lock is released when the connection is closed
        }
    }

    /**
     * Counts a gateway status request for the order.
     * Call it while the order is locked.
     *
     * @param int $orderId
     * @return bool False if the limit of requests is reached
     */
    public function allowStatusRequest($orderId)
    {
        $cache = ObjectManager::getInstance()->get(CacheInterface::class);
        $key = 'payneteasy_status_' . (int) $orderId;
        $now = time();

        $data = json_decode((string) $cache->load($key), true);
        if (!is_array($data) || !isset($data['start'], $data['count']) || $now - (int) $data['start'] >= self::STATUS_PERIOD) {
            $data = ['start' => $now, 'count' => 0];
        }

        if ((int) $data['count'] >= self::STATUS_LIMIT) {
            return false;
        }

        $data['count'] = (int) $data['count'] + 1;
        $cache->save(json_encode($data), $key, [], self::STATUS_PERIOD);

        return true;
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function getConnection()
    {
        return ObjectManager::getInstance()->get(ResourceConnection::class)->getConnection();
    }

    /**
     * Lock name: one per order of this shop (lock names are common for the database server).
     *
     * @param int $orderId
     * @return string
     */
    private function getLockName($orderId)
    {
        $resource = ObjectManager::getInstance()->get(ResourceConnection::class);
        $database = (string) $resource->getConnection()->fetchOne('SELECT DATABASE()');

        return 'payneteasy_' . sha1($database . '|' . $resource->getTableName('sales_order') . '|' . (int) $orderId);
    }
}
