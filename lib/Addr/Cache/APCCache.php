<?php

namespace Addr\Cache;

use Addr\ResolutionErrors;

class APCCache extends KeyValueCache
{
    /**
     * Constructor
     *
     * @param string $keyPrefix A prefix to prepend to all keys.
     */
    public function __construct($keyPrefix = __CLASS__)
    {
        parent::__construct($keyPrefix);
    }

    /**
     * Attempt to retrieve a value from the cache
     *
     * @param string $name
     * @param int $type
     * @param callable $callback
     */
    public function resolve($name, $type, callable $callback)
    {
        $value = apc_fetch($this->generateKey($name, $type), $success);

        if ($success) {
            $callback($value, $type);
            return;
        }

        $callback(null, ResolutionErrors::ERR_NO_RECORD);
    }

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param string $name
     * @param int $type
     * @param string $addr
     * @param int $ttl
     */
    public function store($name, $type, $addr, $ttl = null)
    {
        apc_store($this->generateKey($name, $type), $addr, $ttl);
    }

    /**
     * Deletes an entry from the cache.
     *
     * @param string $name
     * @param int $type
     */
    public function delete($name, $type)
    {
        apc_delete($this->generateKey($name, $type));
    }
}
