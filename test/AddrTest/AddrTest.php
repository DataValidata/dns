<?php

namespace AddrTest;

use Addr\ResolverFactory,
    Addr\Cache,
    Addr\Cache\APCCache,
    Addr\Cache\MemoryCache,
    Addr\Cache\RedisCache,
    Alert\ReactorFactory,
    Predis\Client as RedisClient,
    Predis\Connection\ConnectionException as RedisConnectionException;

class AddrTest extends \PHPUnit_Framework_TestCase
{
    private static $redisEnabled = true;

    private static $redisParameters = [
        'connection_timeout' => 2,
        'read_write_timeout' => 2,
    ];

    public static function setUpBeforeClass()
    {
        try {
            $predisClient = new RedisClient(self::$redisParameters, []);
            $predisClient->ping();
            //It's connected
        }
        catch (RedisConnectionException $rce) {
            self::$redisEnabled = false;
        }
    }

    public function testWithNullCache()
    {
        $this->basicRun(null);
    }

    public function testWithMemoryCache()
    {
        $memoryCache = new MemoryCache();
        $this->basicRun($memoryCache);
    }

    /**
     * @requires extension APC
     */
    public function testWithApcCache()
    {
        $prefix = time().uniqid('CacheTest');
        $apcCache = new APCCache($prefix);
        $this->basicRun($apcCache);
    }

    public function testWithRedisCache()
    {
        if (self::$redisEnabled != true) {
            $this->markTestSkipped("Could not connect to Redis, skipping test.");
            return;
        }

        $prefix = time().'_'.uniqid('CacheTest');
        try {
            $redisClient = new RedisClient(self::$redisParameters, []);
        }
        catch (RedisConnectionException $rce) {
            $this->markTestIncomplete("Could not connect to Redis server, cannot test redis cache.");
            return;
        }

        $redisCache = new RedisCache($redisClient, $prefix);
        $this->basicRun($redisCache);
    }

    /**
     * @group internet
     */
    public function basicRun(Cache $cache = null)
    {
        $names = [
            'google.com',
            'github.com',
            'stackoverflow.com',
            'localhost',
            '192.168.0.1',
            '::1',
        ];

        $reactor = (new ReactorFactory)->select();
        $resolver = (new ResolverFactory)->createResolver(
            $reactor,
            null, //        $serverAddr = null,
            null, //$serverPort = null,
            null, //$requestTimeout = null,
            $cache,
            null //$hostsFilePath = null
        );

        $results = [];

        foreach ($names as $name) {
            $resolver->resolve($name, , function ($addr) use ($name, $resolver, &$results) {
                $results[$name] = $addr;
            });
        }

        $reactor->run();

        foreach ($results as $name => $addr) {
            $validIP = filter_var($addr, FILTER_VALIDATE_IP);
            $this->assertNotFalse(
                $validIP,
                "Server name $name did not resolve to a valid IP address"
            );
        }

        $this->assertCount(
            count($names),
            $results,
            "At least one of the name lookups did not resolve."
        );
    }


    /**
     * Check that caches do actually cache results.
     */
    function testCachingOfResults() {

        $memoryCache = new MemoryCache();

        $namesFirstRun = [
            'google.com',
            'github.com',
            'google.com',
            'github.com',
        ];
        
        $namesSecondRun = [
            'google.com',
            'github.com',
        ];

        $setCount = count(array_unique(array_merge($namesFirstRun, $namesSecondRun)));
        $getCount = count($namesFirstRun) + count($namesSecondRun);
        
        $mockedCache = \Mockery::mock($memoryCache);

        /** @var  $mockedCache \Mockery\Mock */
        
        $mockedCache->shouldReceive('store')->times($setCount)->passthru();
        $mockedCache->shouldReceive('get')->times($getCount)->passthru();

        $mockedCache->makePartial();

        $reactor = (new ReactorFactory)->select();
        $resolver = (new ResolverFactory)->createResolver(
            $reactor,
            null, //$serverAddr = null,
            null, //$serverPort = null,
            null, //$requestTimeout = null,
            $mockedCache,
            null //$hostsFilePath = null
        );

        $results = [];
        
        //Lookup the first set of names
        foreach ($namesFirstRun as $name) {
            $resolver->resolve($name, , function ($addr) use ($name, $resolver, &$results) {
                $results[] = [$name, $addr];
            });
        }

        $reactor->run();

        $this->assertCount(
            count($namesFirstRun),
            $results,
            "At least one of the name lookups did not resolve in the first run."
        );

        $results = [];

        //Lookup a second set of names
        foreach ($namesSecondRun as $name) {
            $resolver->resolve($name, , function ($addr) use ($name, $resolver, &$results) {
                $results[] = [$name, $addr];
            });
        }

        $reactor->run();

        foreach ($results as $nameAndAddr) {
            list($name, $addr) = $nameAndAddr;
            $validIP = filter_var($addr, FILTER_VALIDATE_IP);
            $this->assertNotFalse(
                $validIP,
                "Server name $name did not resolve to a valid IP address"
            );
        }

        $this->assertCount(
            count($namesSecondRun),
            $results,
            "At least one of the name lookups did not resolve in the second run."
        );
    }
}
