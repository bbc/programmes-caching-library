<?php
declare(strict_types=1);

namespace BBC\ProgrammesCachingLibrary;

use DateTime;
use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * A copy of BBC\ProgrammesCachingLibrary\Cache but with added resilience, when using this class you will cache
 * everything for RESILIENCE_TTL time and in case of an error "getOrSet" will return the stale value or alternatively
 * you can use "getItem" with "$returnStaleValue = true"
 */
class CacheWithResilience extends Cache
{
    /** @var LoggerInterface  */
    private $logger;

    /** @var \Exception[]  */
    private $whitelistedExceptions;

    // This is used as a resilience system, this extends the TTL but only is used in case of an error.
    // For example if an API fails we can use the redis stored value instead of displaying nothing or an error.
    private const RESILIENCE_TTL = 21600; // 6h

    public function __construct(
        LoggerInterface $logger,
        CacheItemPoolInterface $cachePool,
        string $prefix,
        array $cacheTimes = [],
        array $whitelistedExceptions = []
    ) {
        $this->logger = $logger;
        $this->whitelistedExceptions = $whitelistedExceptions;
        parent::__construct($cachePool, $prefix, $cacheTimes);
    }

    /**
     * This function get the item if exist in memory and the TTL
     *
     * @param string $key
     * @param bool $returnStaleValue
     * @return CacheItemInterface
     */
    public function getItem(string $key, bool $returnStaleValue = false): CacheItemInterface
    {
        $key = $this->standardiseKey($key);
        if ($this->flushCacheItems) {
            $this->cachePool->deleteItem($key);
        }

        $item = $this->cachePool->getItem($key);
        if ($item->isHit()) {
            $value = $item->get();
            // we have 2 levels of cache, one of them is stored with the item as $value['ttl'] and is the time
            // when we want the item to be consider as expired, the second one is the real TTL that we tell redis.
            // The second one is only mean to be used in case of issues like DB down and only requires to
            // set $returnStaleValue to true
            if (false === $returnStaleValue && new DateTime() > $value['ttl']) {
                return new CacheItem();
            }
        }

        // value was wrapped into an array with the ttl. set the value back to its original value
        $val = $item->get();
        $item->set($val['value']);

        return $item;
    }

    /**
     * @param CacheItemInterface $item
     * @param mixed $value
     * @param int|string|DateTimeInterface $ttl
     *   TTL in seconds, or a constant from CacheInterface, or a DateTime to expire at
     * @return bool
     */
    public function setItem(CacheItemInterface $item, $value, $ttl): bool
    {
        // Calculate the date and time when the element should be consider as expire
        if ($ttl instanceof DateTimeInterface) {
            $expiresAt = $ttl;
        } elseif (\in_array($ttl, [0, -1, CacheInterface::NONE, CacheInterface::INDEFINITE, CacheInterface::SHORT], false)) {
            // this condition handles a list of exception that shouldn't be cached for a different length
            $ttl = $this->calculateTtl($ttl);
            $item->expiresAfter($ttl);
            // getItem() expects the item to be wrapped as [val, ttl]
            $item->set(['value' => $value, 'ttl' => $ttl]);
            return $this->cachePool->save($item);
        } else {
            $ttl = $this->calculateTtl($ttl);
            $expiresAt = new DateTime();
            $expiresAt = $expiresAt->add(new DateInterval('PT' . $ttl . 'S'));
        }

        // store the ttl within the stored item so we can know if a returned key is expired.
        $item->set(['value' => $value, 'ttl' => $expiresAt]);

        // instead of setting the item to expire at $expiresAt we use "RESILIENCE_TTL"
        // this way in case of error we can return the stale value
        $item->expiresAfter(self::RESILIENCE_TTL);

        return $this->cachePool->save($item);
    }

    /**
     * IF CALLABLE RETURNS SOMETHING THAT EVALUATES TO EMPTY THE RESULT WILL NOT BE CACHED UNLESS $nullTtl IS SET
     * TO A VALUE DIFFERENT FROM CacheInterface::NONE
     *
     * @param string $key
     * @param int|string $ttl
     *   TTL in seconds, or a constant from CacheInterface
     * @param callable $function
     * @param array $arguments
     * @return mixed
     */
    public function getOrSet(string $key, $ttl, callable $function, array $arguments = [], $nullTtl = CacheInterface::NONE)
    {
        $cacheItem = $this->getItem($key);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        try {
            $result = $function(...$arguments);
        } catch (\Exception $e) {
            // if exception is whitelisted, return the stale value
            if ($this->isWhitelistedException($e)) {
                $this->logger->error($e->getMessage());
                // return stale value instead of failing
                $cacheItem = $this->getItem($key, true);
                if ($cacheItem->isHit()) {
                    return $cacheItem->get();
                }
            }
            // if the exception is not whitelisted or the item is not in the cached then throw the expected error.
            throw $e;
        }

        if (!empty($result)) {
            $this->setItem($cacheItem, $result, $ttl);
        } elseif ($nullTtl !== CacheInterface::NONE) {
            $this->setItem($cacheItem, $result, $nullTtl);
        }

        return $result;
    }


    private function isWhitelistedException(\Exception $exceptionThrown): bool
    {
        foreach ($this->whitelistedExceptions as $exception) {
            if ($exception instanceof $exceptionThrown) {
                return true;
            }
        }
        return false;
    }
}
