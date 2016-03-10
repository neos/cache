<?php
namespace TYPO3\Flow\Cache\Tests\Unit\Backend;

include_once(__DIR__ . '/../../BaseTestCase.php');

/*
 * This file is part of the Neos.Cache package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */
use TYPO3\Flow\Cache\Backend\RedisBackend;
use TYPO3\Flow\Cache\Tests\BaseTestCase;

/**
 * Testcase for the redis cache backend
 *
 * These unit tests rely on a mocked redis client.
 * @requires extension redis
 */
class RedisBackendTest extends BaseTestCase
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $redis;

    /**
     * @var RedisBackend
     */
    private $backend;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $cache;

    /**
     * Set up test case
     * @return void
     */
    public function setUp()
    {
        $phpredisVersion = phpversion('redis');
        if (version_compare($phpredisVersion, '1.2.0', '<')) {
            $this->markTestSkipped(sprintf('phpredis extension version %s is not supported. Please update to verson 1.2.0+.', $phpredisVersion));
        }

        $this->redis = $this->getMockBuilder(\Redis::class)->disableOriginalConstructor()->getMock();
        $this->cache = $this->getMock(\TYPO3\Flow\Cache\Frontend\FrontendInterface::class);
        $this->cache->expects($this->any())
            ->method('getIdentifier')
            ->will($this->returnValue('Foo_Cache'));

        $mockEnvironmentConfiguration = $this->getMock(\TYPO3\Flow\Cache\EnvironmentConfiguration::class, null, [
            __DIR__,
            'Testing',
            'vfs://Foo/',
            255
        ], '');

        $this->backend = new RedisBackend($mockEnvironmentConfiguration, array(), $this->redis);
        $this->backend->setCache($this->cache);
    }

    /**
     * @test
     */
    public function findIdentifiersByTagInvokesRedis()
    {
        $this->redis->expects($this->once())
            ->method('sMembers')
            ->with('Foo_Cache:tag:some_tag')
            ->will($this->returnValue(array('entry_1', 'entry_2')));

        $this->assertEquals(array('entry_1', 'entry_2'), $this->backend->findIdentifiersByTag('some_tag'));
    }

    /**
     * @test
     */
    public function freezeInvokesRedis()
    {
        $this->redis->expects($this->once())
            ->method('lRange')
            ->with('Foo_Cache:entries', 0, -1)
            ->will($this->returnValue(array('entry_1', 'entry_2')));

        $this->redis->expects($this->exactly(2))
            ->method('persist');

        $this->redis->expects($this->once())
            ->method('set')
            ->with('Foo_Cache:frozen', true);

        $this->backend->freeze();
    }

    /**
     * @test
     */
    public function setUsesDefaultLifetimeIfNotProvided()
    {
        $defaultLifetime = rand(1, 9999);
        $this->backend->setDefaultLifetime($defaultLifetime);
        $expected = array('ex' => $defaultLifetime);

        $this->redis->expects($this->any())
            ->method('multi')
            ->willReturn($this->redis);

        $this->redis->expects($this->once())
            ->method('set')
            ->with($this->anything(), $this->anything(), $expected)
            ->willReturn($this->redis);

        $this->backend->set('foo', 'bar');
    }

    /**
     * @test
     */
    public function setUsesProvidedLifetime()
    {
        $defaultLifetime = 3600;
        $this->backend->setDefaultLifetime($defaultLifetime);
        $expected = array('ex' => 1600);

        $this->redis->expects($this->any())
            ->method('multi')
            ->willReturn($this->redis);

        $this->redis->expects($this->once())
            ->method('set')
            ->with($this->anything(), $this->anything(), $expected)
            ->willReturn($this->redis);

        $this->backend->set('foo', 'bar', array(), 1600);
    }

    /**
     * @test
     */
    public function setAddsEntryToRedis()
    {
        $this->redis->expects($this->any())
            ->method('multi')
            ->willReturn($this->redis);

        $this->redis->expects($this->once())
            ->method('set')
            ->with('Foo_Cache:entry:entry_1', 'foo')
            ->willReturn($this->redis);

        $this->backend->set('entry_1', 'foo');
    }

    /**
     * @test
     */
    public function getInvokesRedis()
    {
        $this->redis->expects($this->once())
            ->method('get')
            ->with('Foo_Cache:entry:foo')
            ->will($this->returnValue('bar'));

        $this->assertEquals('bar', $this->backend->get('foo'));
    }

    /**
     * @test
     */
    public function hasInvokesRedis()
    {
        $this->redis->expects($this->once())
            ->method('exists')
            ->with('Foo_Cache:entry:foo')
            ->will($this->returnValue(true));

        $this->assertEquals(true, $this->backend->has('foo'));
    }

    /**
     * @test
     * @dataProvider writingOperationsProvider
     * @expectedException \RuntimeException
     */
    public function writingOperationsThrowAnExceptionIfCacheIsFrozen($method)
    {
        $this->redis->expects($this->once())
            ->method('exists')
            ->with('Foo_Cache:frozen')
            ->will($this->returnValue(true));

        $this->backend->$method('foo', 'bar');
    }

    /**
     * @return array
     */
    public static function writingOperationsProvider()
    {
        return array(
            array('set'),
            array('remove'),
            array('flushByTag'),
            array('freeze')
        );
    }
}
