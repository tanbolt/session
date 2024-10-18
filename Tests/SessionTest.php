<?php

use Tanbolt\Session\Session;
use PHPUnit\Framework\TestCase;
use Tanbolt\Session\Fixtures\SessionHandler;

class SessionTest extends TestCase
{
    public function setUp():void
    {
        PHPUNIT_LOADER::addDir('Tanbolt\Session\Fixtures', __DIR__.'/Fixtures');
        parent::setUp();
    }

    public function testSessionBasic()
    {
        $s = new Session();
        $save_handler = ini_get('session.save_handler');
        $save_path = ini_get('session.save_path');
        static::assertEquals($save_handler, $s->getSaveHandler());
        static::assertEquals($save_path, $s->getSavePath());
        static::assertEquals($save_handler, $s->getIni('save_handler'));
        static::assertEquals($save_path, $s->getIni('save_path'));

        $s = new Session('handler', 'path');
        static::assertEquals('handler', $s->getSaveHandler());
        static::assertEquals('path', $s->getSavePath());

        $s->setSaveHandler('newHandler')->setSavePath(['foo']);
        static::assertEquals('newHandler', $s->getSaveHandler());
        static::assertEquals(['foo'], $s->getSavePath());
    }

    public function testSessionIni()
    {
        $s = new Session();
        $s->setIni([
           'use_cookies' => 1,
           'use_only_cookies' => 1,
        ]);
        static::assertEquals(1, ini_get('session.use_cookies'));
        static::assertEquals(1, ini_get('session.use_only_cookies'));
    }

    public function testCookieName()
    {
        $s = new Session();
        static::assertEquals(ini_get('session.name'), $s->getCookieName());
        static::assertSame($s, $s->setCookieName('test'));
        static::assertEquals('test', $s->getCookieName());
        static::assertEquals('test', ini_get('session.name'));
    }

    public function testCookieLifetime()
    {
        $s = new Session();
        static::assertEquals(ini_get('session.cookie_lifetime'), $s->getCookieLifetime());
        static::assertSame($s, $s->setCookieLifetime(666));
        static::assertEquals(666, $s->getCookieLifetime());
        static::assertEquals(666, ini_get('session.cookie_lifetime'));
    }

    public function testCookiePath()
    {
        $s = new Session();
        static::assertEquals(ini_get('session.cookie_path'), $s->getCookiePath());
        static::assertSame($s, $s->setCookiePath('/foo'));
        static::assertEquals('/foo', $s->getCookiePath());
        static::assertEquals('/foo', ini_get('session.cookie_path'));
    }

    public function testCookieDomain()
    {
        $s = new Session();
        static::assertEquals(ini_get('session.cookie_domain'), $s->getCookieDomain());
        static::assertSame($s, $s->setCookieDomain('foo.com'));
        static::assertEquals('foo.com', $s->getCookieDomain());
        static::assertEquals('foo.com', ini_get('session.cookie_domain'));
        $s->setCookieDomain(null);
        static::assertEmpty($s->getCookieDomain());
    }

    public function testCookieSameSite()
    {
        $s = new Session();
        static::assertEquals(ini_get('session.cookie_samesite'), $s->getCookieSameSite());
        static::assertSame($s, $s->setCookieSameSite('Lax'));
        if (PHP_VERSION_ID > 70300) {
            static::assertEquals('Lax', $s->getCookieSameSite());
            static::assertEquals('Lax', ini_get('session.cookie_samesite'));
            $s->setCookieSameSite(null);
            static::assertEmpty($s->getCookieSameSite());
        }
    }

    public function testCookieHttpOnly()
    {
        $s = new Session();
        static::assertEquals((bool) ini_get('session.cookie_httponly'), $s->isCookieHttpOnly());
        static::assertSame($s, $s->setCookieHttpOnly(true));
        static::assertTrue($s->isCookieHttpOnly());
        static::assertTrue((bool) ini_get('session.cookie_httponly'));

        static::assertSame($s, $s->setCookieHttpOnly(false));
        static::assertFalse($s->isCookieHttpOnly());
        static::assertFalse((bool) ini_get('session.cookie_httponly'));
    }

    public function testCookieSecure()
    {
        $s = new Session();
        static::assertEquals((bool) ini_get('session.cookie_secure'), $s->isCookieSecure());
        static::assertSame($s, $s->setCookieSecure(true));
        static::assertTrue($s->isCookieSecure());
        static::assertTrue((bool) ini_get('session.cookie_secure'));

        static::assertSame($s, $s->setCookieSecure(false));
        static::assertFalse($s->isCookieSecure());
        static::assertFalse((bool) ini_get('session.cookie_secure'));
    }

    public function testSessionCookie()
    {
        $s = new Session();
        $s->setCookieLifetime(88)->setCookieDomain('bar.com')->setCookiePath('/bar')
            ->setCookieHttpOnly(true)->setCookieSecure(true);
        $params = session_get_cookie_params();
        ksort($params);
        if (isset($params['samesite'])) {
            static::assertEquals('', $params['samesite']);
            unset($params['samesite']);
        }
        static::assertEquals([
            'domain' => 'bar.com',
            'httponly' => true,
            'lifetime' => 88,
            'path' => '/bar',
            'secure' => true
        ], $params);
    }

    public function testSaveHandlerByPromise()
    {
        $s = new Session();
        $s->setSavePath('');
        $s->setSaveHandler('memory');
        static::assertEquals('memory', $s->getSaveHandler());

        $testSessionId = 'SessionHandlerInterface'.time();
        $s->setId($testSessionId);
        $s->start();
        $s->set('foo','bar');
        static::assertEquals('bar', $s['foo']);
        $s->close();

        $s->start();
        static::assertEquals(['foo' => 'bar'], $s->all());
        $s->destroy();
        static::assertEquals(['foo' => 'bar'], $s->all());
        $s->close();

        $s->start();
        static::assertEquals([], $s->all());
        $s->close();
    }

    public function testSaveHandlerByCustomInterface()
    {
        $s = new Session();
        $s->setSavePath('');
        $sessionHandler = SessionHandler::class;
        $s->setSaveHandler($sessionHandler);
        static::assertEquals($sessionHandler, $s->getSaveHandler());

        $testSessionId = 'SessionHandlerInterface'.time();
        $s->setId($testSessionId);
        $s->start();
        $s->set('foo','bar');
        static::assertEquals('bar', $s['foo']);
        $s->close();

        $s->start();
        static::assertEquals(['foo' => 'bar'], $s->all());
        $s->destroy();
        static::assertEquals(['foo' => 'bar'], $s->all());
        $s->close();

        $s->start();
        static::assertEquals([], $s->all());
        $s->close();
    }

    public function testSaveHandlerByCustomHandler()
    {
        $s = new Session();
        $s->setSavePath('');
        $sessionHandler = new SessionHandler();
        $s->setSaveHandler($sessionHandler);
        static::assertSame($sessionHandler, $s->getSaveHandler());

        $testSessionId = 'SessionHandlerObject'.time();
        $s->setId($testSessionId);
        $s->start();
        $s->set('foo','bar');
        static::assertEquals('bar', $s['foo']);
        $s->close();
        static::assertEquals('foo|s:3:"bar";', $sessionHandler->getTmp($testSessionId));
        static::assertEquals('foo|s:3:"bar";', $s->encode());

        $s->start();
        static::assertEquals(['foo' => 'bar'], $s->all());
        $s->destroy();
        static::assertEquals(['foo' => 'bar'], $s->all());
        static::assertEquals([], $sessionHandler->getTmp());
        $s->close();

        $s->start();
        static::assertEquals([], $s->all());
        $s->close();
    }

    public function testSaveHandlerByCustomFunction()
    {
        $s = new Session();
        $s->setSaveMethod(
            function() {
                return true;
            },
            function() {
                return true;
            },
            function($sessionId) {
                global $sessionTmpArrForTest;
                if (isset($sessionTmpArrForTest[$sessionId])) {
                    return (string) $sessionTmpArrForTest[$sessionId];
                }
                return '';
            },
            function($sessionId, $data) {
                global $sessionTmpArrForTest;
                $sessionTmpArrForTest[$sessionId] = $data;
                return true;
            },
            function($sessionId){
                global $sessionTmpArrForTest;
                unset($sessionTmpArrForTest[$sessionId]);
                return true;
            },
            function() {
                return true;
            }
        );
        static::assertFalse(isset($GLOBALS['sessionTmpArrForTest']));
        $testSessionId = 'SessionHandler'.time();

        $s->setId($testSessionId);
        $s->start();
        $s->set('foo','bar');
        static::assertEquals('bar', $s['foo']);
        $s->close();
        static::assertEquals('foo|s:3:"bar";', $GLOBALS['sessionTmpArrForTest'][$testSessionId]);

        $s->start();
        static::assertEquals(['foo' => 'bar'], $s->all());
        $s->destroy();
        static::assertEquals(['foo' => 'bar'], $s->all());
        static::assertEquals([], $GLOBALS['sessionTmpArrForTest']);
        $s->close();

        $s->start();
        static::assertEquals([], $s->all());
        $s->close();
        unset($GLOBALS['sessionTmpArrForTest']);
    }

    public function testSavePath()
    {
        $s = new Session();
        $iniPath = ini_get('session.save_path');
        static::assertEquals($iniPath, $s->getSavePath());
        static::assertEquals($s, $s->setSavePath('test'));
        static::assertEquals('test', $s->getSavePath());
        static::assertEquals($s, $s->setSavePath(['foo' => 'bar']));
        static::assertEquals(['foo' => 'bar'], $s->getSavePath());
    }

    public function testSaveHandlerChange()
    {
        $s = new Session();

        $testSessionId = 'SessionHandlerObject'.time();
        $s->setId($testSessionId);

        // 基本
        $sessionHandler = new SessionHandler();
        $s->setSaveHandler($sessionHandler)->setSavePath('f')->start();
        $s->set('foo','foo');
        static::assertEquals('foo', $s['foo']);
        $s->close();
        // after close
        static::assertEquals(['foo' => 'foo'], $s->all());
        static::assertEquals('f', $sessionHandler->getPath());
        static::assertEquals('foo|s:3:"foo";', $sessionHandler->getTmp($testSessionId));

        // 仅修改 path
        $s->setSavePath('x')->start();
        static::assertEquals('foo', $s['foo']);
        static::assertEquals('x', $sessionHandler->getPath());
        $s->set('bar','bar');
        $s->close();
        // after close
        static::assertEquals(['foo' => 'foo', 'bar' => 'bar'], $s->all());
        static::assertEquals('x', $sessionHandler->getPath());
        static::assertEquals('foo|s:3:"foo";bar|s:3:"bar";', $sessionHandler->getTmp($testSessionId));

        // 仅修改 handler
        $newHandler = new SessionHandler();
        $s->setSaveHandler($newHandler)->start();
        static::assertNull($s['foo']);
        static::assertEquals('x', $newHandler->getPath());
        $s->set('foo2','foo2');
        $s->close();
        // after close
        static::assertEquals(['foo2' => 'foo2'], $s->all());
        static::assertEquals('x', $sessionHandler->getPath());
        static::assertEquals('x', $newHandler->getPath());
        static::assertEquals('foo|s:3:"foo";bar|s:3:"bar";', $sessionHandler->getTmp($testSessionId));
        static::assertEquals('foo2|s:4:"foo2";', $newHandler->getTmp($testSessionId));

        // 同时修改 handler path
        $lastHandler = new SessionHandler();
        $s->setSaveHandler($lastHandler)->setSavePath('s')->start();
        static::assertNull($s['foo']);
        static::assertEquals('s', $lastHandler->getPath());
        $s->set('bar2','bar2');
        $s->close();
        // after close
        static::assertEquals(['bar2' => 'bar2'], $s->all());
        static::assertEquals('x', $sessionHandler->getPath());
        static::assertEquals('x', $newHandler->getPath());
        static::assertEquals('s', $lastHandler->getPath());
        static::assertEquals('foo|s:3:"foo";bar|s:3:"bar";', $sessionHandler->getTmp($testSessionId));
        static::assertEquals('foo2|s:4:"foo2";', $newHandler->getTmp($testSessionId));
        static::assertEquals('bar2|s:4:"bar2";', $lastHandler->getTmp($testSessionId));
    }

    public function testSessionId()
    {
        $s = new Session();
        static::assertEmpty($s->getId());
        static::assertSame($s, $s->setId('test'));
        static::assertEquals('test', $s->getId());
    }

    public function testSessionStart()
    {
        $s = new Session();
        $s->setId('SessionTest');
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);
        static::assertTrue($s->isStart());
        static::assertTrue(is_file(__DIR__.'/Fixtures/sess_SessionTest'));
        $s->destroy();
    }

    public function testDestroyAfterStartTest()
    {
        static::assertFalse(is_file(__DIR__.'/Fixtures/sess_SessionTest'));
    }

    public function testUnknownSessionStart()
    {
        try {
            $s = new Session();
            $s->start([
                'save_handler' => 'unknown',
                'save_path'    => 'path',
            ]);
        } catch (Exception $e) {
            static::assertTrue(true);
            return;
        }
        static::fail('It should be throw exception when session handler unknown');
    }

    public function testSessionSendCookie()
    {
        $s = new Session();
        $s->setSaveHandler(new SessionHandler())->setCookieName('phpSid');

        // 使用原生手段自动处理 cookie
        static::assertEmpty($s->getResponseCookie());
        $s->start();
        static::assertEmpty($s->getResponseCookie());
        $s->__destruct();

        // 使用内在逻辑, 需发送 cookie
        $s->setRequestCookies([
           'phpVid' => 'foo'
        ]);
        static::assertEmpty($s->getResponseCookie());
        $s->start();

        $cookie = $s->getResponseCookie();
        $cookieStr = $s->getResponseCookie(true);
        static::assertEquals('phpSid', $cookie['name']);
        static::assertNotEquals('foo', $cookie['value']);
        static::assertTrue(false !== strpos($cookieStr, $cookie['name'].'='.$cookie['value']));
        static::assertEquals(0, $s->getCookieLifetime());
        static::assertFalse(false !== strpos($cookieStr, 'Expires='));

        $s->__destruct();

        // 使用内在逻辑, 无需发送 cookie
        $s->setRequestCookies([
            'phpSid' => 'foo'
        ]);
        static::assertEmpty($s->getResponseCookie());
        $s->start();
        static::assertEmpty($s->getResponseCookie());
        $s->close();
    }

    public function testSessionValue()
    {
        $testSessionId = 'SessionValueTest'.time();
        $s = new Session();
        $s->setId($testSessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
            'serialize_handler' => 'php'
        ]);

        // basic
        static::assertEquals([], $s->all());
        static::assertSame($s, $s->set('foo', 'bar'));
        static::assertEquals('bar', $s->get('foo'));
        $s->set('arr', ['foo']);
        static::assertEquals(['foo'], $s->get('arr'));
        static::assertTrue($s->has('foo'));
        static::assertFalse($s->has('none'));

        // encode decode
        static::assertEquals('foo|s:3:"bar";arr|a:1:{i:0;s:3:"foo";}', $s->encode());
        static::assertSame($s, $s->decode('hello|s:5:"world";'));
        static::assertTrue($s->has('foo'));
        static::assertTrue($s->has('hello'));
        static::assertEquals('world', $s->get('hello'));

        // remove one
        static::assertSame($s, $s->remove('hello'));
        static::assertFalse($s->has('hello'));
        static::assertNull($s->get('hello'));
        static::assertEquals('default', $s->get('hello','default'));

        $s->destroy();
    }

    public function testRegenerateNotRemoveFile()
    {
        $testSessionId = 'SessionRegenerateNotRemoveFileTest'.time();
        $s = new Session();
        $s->setId($testSessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);
        static::assertTrue($s->isStart());
        $s->set('foo', 'bar')->regenerate();
        $sessionId = $s->getId();
        static::assertNotEquals($testSessionId, $sessionId);
        static::assertEquals('bar', $s->get('foo'));
        $s->close();
        static::assertTrue(is_file(__DIR__.'/Fixtures/sess_'.$testSessionId));
        static::assertTrue(is_file(__DIR__.'/Fixtures/sess_'.$sessionId));
        static::assertFalse($s->isStart());

        $s->setId($testSessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);
        $s->destroy();
        static::assertFalse($s->isStart());

        $s->setId($sessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);
        $s->destroy();
        static::assertFalse($s->isStart());
    }

    public function testRegenerateRemoveFile()
    {
        $testSessionId = 'SessionRegenerateRemoveFileTest'.time();
        $s = new Session();
        $s->setId($testSessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);
        $s->set('foo', 'bar')->regenerate(true);
        $sessionId = $s->getId();
        static::assertNotEquals($testSessionId, $sessionId);
        static::assertEquals('bar', $s->get('foo'));
        $s->close();
        static::assertFalse(is_file(__DIR__.'/Fixtures/sess_'.$testSessionId));
        static::assertTrue(is_file(__DIR__.'/Fixtures/sess_'.$sessionId));

        $s->setId($sessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);
        $s->destroy();
    }

    public function testAbortAndResetAndClear()
    {
        $testSessionId = 'SessionAbortTest'.time();
        $s = new Session();
        $s->setId($testSessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);

        $s->set('foo', 'bar')->set('hello', 'world');
        static::assertEquals([
            'foo' => 'bar',
            'hello' => 'world',
        ], $s->all());

        // reset
        static::assertSame($s, $s->reset());
        static::assertEquals([], $s->all());

        // clear
        $s->set('foo', 'bar')->set('hello', 'world');
        static::assertSame($s, $s->clear());
        static::assertEquals([], $s->all());

        // abort
        static::assertSame($s, $s->abort());
        $s->set('foo', 'bar');
        static::assertEquals([
            'foo' => 'bar',
        ], $s->all());

        // clear after abort(close)
        $s->clear();
        static::assertEquals([
            'foo' => 'bar',
        ], $s->all());

        $s->setId($testSessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);
        static::assertEquals([], $s->all());
        $s->set('foo', 'bar')->set('hello', 'world');

        // close
        static::assertEquals($s, $s->close());
        $s->set('key', 'value');
        static::assertEquals([
            'foo' => 'bar',
            'hello' => 'world',
            'key' => 'value',
        ], $s->all());

        // test reset after close
        $s->reset();
        static::assertEquals([
            'foo' => 'bar',
            'hello' => 'world',
            'key' => 'value',
        ], $s->all());

        // test abort after close
        static::assertSame($s, $s->abort());
        $s->remove('foo');
        static::assertEquals([
            'hello' => 'world',
            'key' => 'value',
        ], $s->all());

        // test clear after close
        $s->clear();
        static::assertEquals([
            'hello' => 'world',
            'key' => 'value',
        ], $s->all());

        // test again with initial value
        $s->setId($testSessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);

        static::assertEquals([
            'foo' => 'bar',
            'hello' => 'world',
        ], $s->all());

        // reset
        $s->set('key', 'value');
        static::assertEquals([
            'foo' => 'bar',
            'hello' => 'world',
            'key' => 'value',
        ], $s->all());
        $s->reset();
        static::assertEquals([
            'foo' => 'bar',
            'hello' => 'world',
        ], $s->all());

        // clear
        $s->clear();
        static::assertEquals([], $s->all());
        $s->set('other','none');

        // abort
        $s->abort();
        $s->setId($testSessionId);
        $s->start([
            'save_handler' => 'files',
            'save_path' => __DIR__.'/Fixtures',
            'use_cookies' => 0,
        ]);
        static::assertEquals([
            'foo' => 'bar',
            'hello' => 'world',
        ], $s->all());

        $s->destroy();
    }

    public function testBootConfig()
    {
        $s = new Session();

        // 固定初始化配置
        $cookie_path = ini_get('session.cookie_path');
        $handler = new SessionHandler();
        $s->setIni([
            'cache_expire' => 120,
            'save_handler' => $handler,
            'save_path' => 'foo'
        ], true);

        // 验证初始化配置
        static::assertEquals(120, $s->getIni('cache_expire'));
        static::assertEquals($cookie_path, $s->getIni('cookie_path'));
        static::assertEquals('foo', $s->getSavePath());
        static::assertEquals($handler, $s->getSaveHandler());

        // 重置参数
        $s->setIni('cache_expire', 180)
            ->setIni('cookie_path', '/bar')
            ->setSavePath('bar')
        ->setSaveHandler(SessionHandler::class);

        // 确认参数重置成功
        static::assertEquals(180, $s->getIni('cache_expire'));
        static::assertEquals('/bar', $s->getIni('cookie_path'));
        static::assertEquals('bar', $s->getSavePath());
        static::assertEquals(SessionHandler::class, $s->getSaveHandler());

        // 手动销毁后 恢复为初始化参数
        $s->__destruct();
        static::assertEquals(120, $s->getIni('cache_expire'));
        static::assertEquals($cookie_path, $s->getIni('cookie_path'));
        static::assertEquals('foo', $s->getSavePath());
        static::assertEquals($handler, $s->getSaveHandler());
    }
}
