<?php
declare(strict_types=1);

namespace BBC\ProgrammesCachingLibrary;

use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * An implementation of stale-if-error for the Cache library.
 * This class is compatible with BBC\ProgrammesCachingLibrary\Cache, the only difference when calling the functions
 * is that you can call getItem() with an extra parameter to get the stale value.
 */
class CacheWithResilience extends Cache
{
    /** @var LoggerInterface  */
    private $logger;

    /** @var string[] an array with full class name of exceptions */
    private $whitelistedExceptions;

    // How long items will persist in the cache server.
    // This also mean for how long we could serve stale content in case of issues
    private $resilienceTtl;

    private $staleContentServedCounter = 0;

    public function __construct(
        LoggerInterface $logger,
        CacheItemPoolInterface $cachePool,
        string $prefix,
        int $resilienceTtl,
        array $cacheTimes = [],
        array $whitelistedExceptions = []
    ) {
        $this->logger = $logger;
        $this->resilienceTtl = $resilienceTtl;
        $this->whitelistedExceptions = $whitelistedExceptions;
        parent::__construct($cachePool, $prefix . '.resilient', $cacheTimes);
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
            // we have 2 TTL's, one of them is stored with the item as $value['expiresAt'] and is the time
            // when we want the item to be consider as expired, the second one is the real TTL that we tell redis.
            // The second one is only mean to be used in case of issues like DB down and only requires to
            // set $returnStaleValue to true
            if (false === $returnStaleValue && time() > $value['expiresAt']) {
                return new CacheItem();
            }
        }

        // in setItem() value gets wrapped into an array. set the item to its original value
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
        if (\in_array($ttl, [0, -1, CacheInterface::NONE, CacheInterface::INDEFINITE, CacheInterface::SHORT], true)) {
            // this case handles a list of exception that shouldn't be cached for a different length
            $ttl = $this->calculateTtl($ttl);
            $item->expiresAfter($ttl);
            // getItem() expects the item to be wrapped as [value, expiresAt]
            $item->set(['value' => $value, 'expiresAt' => time() + $ttl]);
            return $this->cachePool->save($item);
        }

        // this condition calculates the unix timestamp when the element should be consider as expire
        if ($ttl instanceof DateTimeInterface) {
            $expiresAt = $ttl->getTimestamp();
        } else {
            $ttl = $this->calculateTtl($ttl);
            $expiresAt = time() + $ttl;
        }
        // save the expire time within the stored item so we can know if a returned key is expired.
        $item->set(['value' => $value, 'expiresAt' => $expiresAt]);
        // instead of setting the item to expire at $expiresAt we use "$resilienceTtl"
        // this way in case of error we can return the stale value
        $item->expiresAfter($this->resilienceTtl);
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
                // return stale value instead of failing
                $cacheItem = $this->getItem($key, true);
                if ($cacheItem->isHit()) {
                    $this->staleContentServedCounter++;
                    $this->logger->error('stale-if-error number ' . $this->staleContentServedCounter . ' served for: ' . $key);
                    $this->logger->error($e->getMessage());
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
            if ($exceptionThrown instanceof $exception) {
                return true;
            }
        }
        return false;
    }
}
