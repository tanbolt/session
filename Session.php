<?php
namespace Tanbolt\Session;

use Countable;
use ArrayAccess;
use ErrorException;
use JsonSerializable;
use RuntimeException;
use SessionHandlerInterface;
use InvalidArgumentException;

/**
 * Class Session: PHP Session 的封装
 * - 可以零配置，直接使用 Session 类，默认会自动使用 php.ini 中的配置
 * - 也可以通过 Session 对象配置，会自动缓存并动态配置 php.ini，所以可使用 $_SESSION 设置 session, 与原生无异
 * - 但不建议直接 $\_SESSION, 因为 Session 类内部处理了兼容等问题, 且为了以后的维护方便（比如 Session 类修改了逻辑）
 *     而是使用 $session = new Session() ,  $session 当作 $_SESSION 使用
 *
 * 其他: 更新后给参数添加了 类型限定， 所以该类原则需要 php7.1 之后版本
 * 由于之前开发时兼容了旧版本 php，也没什么影响, 仍保留在了代码中，
 * @package Tanbolt\Session
 */
class Session implements SessionInterface, ArrayAccess, Countable, JsonSerializable
{
    /**
     * php.ini 中设置的 session save_handler
     * @var string
     */
    protected $iniSaveHandler = null;

    /**
     * php.ini 中设置的 session save_path
     * @var string
     */
    protected $iniSavePath = null;

    /**
     * 自定义 会话存储 handler, string 可以是原生 handler 或 class 类名
     * @var string|SessionHandlerInterface
     */
    protected $userSaveHandler = null;

    /**
     * 自定义 会话存储 path
     * @var mixed
     */
    protected $userSavePath = null;

    /**
     * 当前实际 save handler, 当 $realSaveHandler 为 string 时，为原生 handler
     * @var string|SessionHandlerInterface
     */
    protected $realSaveHandler = null;

    /**
     * 当前实际 save path, 设置到 php.ini 的 path, 若 save handler 未接口类, 可能实际使用 $userSavePath
     * @var string
     */
    protected $realSavePath = null;

    /**
     * 是否将 session_write_close 是否注册到 shutdown
     * @var bool
     */
    protected $registerShutdown = true;

    /**
     * 是否已注册过 session_commit
     * @var bool
     */
    protected $hasRegister = false;

    /**
     * session 是否已经启动
     * @var bool
     */
    protected $started = false;

    /**
     * start 之后获取到的服务端 $_SESSION
     * 是为了兼容 < php5.6
     * @var array
     */
    protected $oldSession = [];

    /**
     * 首次配置后, session ini 初始设置
     * @var array
     */
    private $bootConfig = null;

    /**
     * 首次配置后, session 实际 save handler
     * @var string
     */
    private $bootSaveHandler = null;

    /**
     * 首次配置后, session 实际 save path
     * @var string|array
     */
    private $bootSavePath = null;

    /**
     * 首次配置后, $registerShutdown 设置
     * @var bool
     */
    private $bootShutDown = null;

    /**
     * 当前生命周期内, 相对于 初始配置 改动的 配置项
     * @var array
     */
    private $lifecycleOptions = [];

    /**
     * 运行 Session 组件前，原生的 session_use_cookie 设置
     * @var int
     */
    private $useCookiesIni = null;

    /**
     * 当前使用的自定义 session cookie 数组
     * @var string
     */
    private $customCookies = null;

    /**
     * 初始化类 默认取消原生 session 的 header 影响, 输出统一交给 response 完成
     * @param SessionHandlerInterface|string|null $handler
     * @param mixed $path
     * @throws
     */
    public function __construct($handler = null, $path = null)
    {
        if (!headers_sent()) {
            session_cache_limiter('');
        }
        $this->iniSaveHandler = ini_get('session.save_handler');
        $this->iniSavePath = ini_get('session.save_path');
        if (null !== $handler) {
            $this->setSessionSaveHandler($handler, true, true);
        }
        if (null !== $path) {
            $this->setSessionSavePath($path, true);
        }
    }

    /**
     * 设置 和 session 相关的 ini
     * 1. 当 is_array($name) && $value===true  认为是初始配置并记录，
     *    使用守护进程运行时, 处理完一个请求, __destruct 会恢复初始配置
     * 2. 若当前 session 已启动, 那么本次设置可能不会生效, 所以请确保在 start() 前配置
     * @param string|array $name
     * @param ?string|bool $value
     * @return $this
     * @throws
     */
    public function setIni($name, $value = null)
    {
        $options = [];
        $lockIni = false;
        if (is_array($name)) {
            $options = $name;
            if (true === $value) {
                $lockIni = true;
            }
        } else if (is_string($name)) {
            if (empty($name)) {
                return $this;
            }
            $options[$name] = $value;
        }
        $bootConfig = $this->bootConfig ?: [];
        foreach ($options as $key => $val) {
            if ('save_handler' == $key) {
                $this->setSessionSaveHandler($val, true, $lockIni);
            } elseif ('save_path' == $key) {
                $this->setSessionSavePath($val, $lockIni);
            } else {
                $current = ini_get('session.'.$key);
                if ($current === $val) {
                    continue;
                }
                ini_set('session.'.$key, $val);
                if ($lockIni) {
                    // 初始配置
                    $bootConfig[$key] = $val;
                } else {
                    // 二次设置
                    $this->lifecycleOptions[$key] = 1;
                    // 初始配置中并不存在, 则初始配置使用的是 ini 默认设置
                    if ($this->bootConfig && !array_key_exists($key, $this->bootConfig)) {
                        $this->bootConfig[$key] = $current;
                    }
                }
            }
        }
        // 作为初始配置
        if ($lockIni) {
            if ($this->isStart()) {
                throw new RuntimeException('A session had already been started');
            }
            $this->loadSaveHandlerAndPath();
            $bootConfig['save_handler'] = $this->userSaveHandler;
            $bootConfig['save_path'] = $this->userSavePath;
            $this->bootConfig = $bootConfig;
            $this->bootSaveHandler = $this->realSaveHandler;
            $this->bootSavePath = $this->realSavePath;
            $this->bootShutDown = $this->registerShutdown;
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getIni(string $name, $default = false)
    {
        return false === ($ini = ini_get('session.'.$name)) ? $default : $ini;
    }

    /**
     * 判断 ini 设置值是否为开启状态 (yes on true 1)
     * @param string $name
     * @return bool
     */
    public function isIni(string $name)
    {
        return false !== filter_var($this->getIni($name), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 快速设置 Session 的 cookie 名称
     * @param string $name
     * @return $this
     */
    public function setCookieName(string $name)
    {
        return $this->setIni('name', $name);
    }

    /**
     * 快速获取 Session 的 cookie 名称
     * @return string
     */
    public function getCookieName()
    {
        return $this->getIni('name');
    }

    /**
     * 快速设置 Session 的 cookie 的作用路径
     * @param string $path
     * @return $this
     */
    public function setCookiePath(string $path)
    {
        return $this->setIni('cookie_path', $path);
    }

    /**
     * 快速获取 Session 的 cookie 的作用路径
     * @return string
     */
    public function getCookiePath()
    {
        return $this->getIni('cookie_path');
    }

    /**
     * 快速设置 Session 的 cookie 生命周期
     * @param int $lifetime
     * @return $this
     */
    public function setCookieLifetime(int $lifetime)
    {
        return $this->setIni('cookie_lifetime', $lifetime);
    }

    /**
     * 快速获取 Session 的 cookie 生命周期
     * @return int
     */
    public function getCookieLifetime()
    {
        return (int) $this->getIni('cookie_lifetime');
    }

    /**
     * 快速设置 Session 的 cookie 的作用域名
     * @param ?string $domain
     * @return $this
     */
    public function setCookieDomain(?string $domain)
    {
        return $this->setIni('cookie_domain', $domain);
    }

    /**
     * 快速获取 Session 的 cookie 的作用域名
     * @return string
     */
    public function getCookieDomain()
    {
        return $this->getIni('cookie_domain');
    }

    /**
     * 快速设置 Session 的 cookie 的 sameSite
     * @param ?string $sameSite
     * @return $this
     */
    public function setCookieSameSite(?string $sameSite)
    {
        return $this->setIni('cookie_samesite', $sameSite);
    }

    /**
     * 快速获取 Session 的 cookie 的 sameSite
     * @return string
     */
    public function getCookieSameSite()
    {
        return $this->getIni('cookie_samesite');
    }

    /**
     * 快速设置 Session 的 cookie 是否 httpOnly
     * @param bool $httpOnly
     * @return $this
     */
    public function setCookieHttpOnly(bool $httpOnly)
    {
        return $this->setIni('cookie_httponly', $httpOnly);
    }

    /**
     * 快速获取 Session 的 cookie 是否 httpOnly
     * @return bool
     */
    public function isCookieHttpOnly()
    {
       return $this->isIni('cookie_httponly');
    }

    /**
     * 设置 Session 的 cookie 是否只在 https 时才传输
     * @param bool $secure
     * @return $this
     */
    public function setCookieSecure(bool $secure)
    {
        return $this->setIni('cookie_secure', $secure);
    }

    /**
     * 获取 Session 的 cookie 是否只在 https 时才传输
     * @return bool
     */
    public function isCookieSecure()
    {
        return $this->isIni('cookie_secure');
    }

    /**
     * @inheritDoc
     * @throws
     */
    public function setSaveHandler($handler, bool $registerShutdown = true)
    {
        return $this->setSessionSaveHandler($handler, $registerShutdown);
    }

    /**
     * @inheritDoc
     * @throws
     */
    public function setSaveMethod(
        callable $open,
        callable $close,
        callable $read,
        callable $write,
        callable $destroy,
        callable $gc
    ) {
        return $this->setSessionSaveHandler(new Store($this, $open, $close, $read, $write, $destroy, $gc));
    }

    /**
     * @inheritDoc
     */
    public function getSaveHandler()
    {
        return $this->userSaveHandler ?: $this->iniSaveHandler;
    }

    /**
     * @inheritDoc
     */
    public function setSavePath($path)
    {
        return $this->setSessionSavePath($path);
    }

    /**
     * @inheritDoc
     */
    public function getSavePath()
    {
        return $this->userSavePath ?: $this->iniSavePath;
    }

    /**
     * 设置 session 驱动器
     * @param SessionHandlerInterface|string $handler
     * @param bool $registerShutdown
     * @param bool $first
     * @return $this
     * @throws ErrorException
     */
    protected function setSessionSaveHandler($handler, bool $registerShutdown = true, bool $first = false)
    {
        if ($handler !== $this->userSaveHandler) {
            // 修改了 session 驱动器
            $this->userSaveHandler = $this->realSaveHandler = null;
            if ($handler instanceof SessionHandlerInterface) {
                $this->userSaveHandler = $handler;
            } elseif (is_string($handler)) {
                $this->userSaveHandler = $handler;
            }
            if (null === $this->userSaveHandler) {
                throw new ErrorException('Session saveHandler() must be string or instance of SessionHandlerInterface');
            }
        } elseif ($registerShutdown !== $this->registerShutdown && $this->realSaveHandler && !is_string($this->realSaveHandler)) {
            // 未修改驱动器, 但 修改了 registerShutdown + 非原生驱动器已初始化
            $this->realSaveHandler = null;
        }
        $this->registerShutdown = $registerShutdown;
        if (!$first && null === $this->realSaveHandler) {
            $this->lifecycleOptions['save_handler'] = 1;
        }
        return $this;
    }

    /**
     * 设置 session 驱动器所需 path
     * @param string|array $path
     * @param bool $first
     * @return $this
     */
    protected function setSessionSavePath($path, bool $first = false)
    {
        if (is_string($path) && is_dir($path)) {
            $path = realpath($path);
        }
        if ($path !== $this->userSavePath) {
            $this->realSavePath = null;
            $this->userSavePath = $path;
            if (!$first) {
                $this->lifecycleOptions['save_path'] = 1;
            }
        }
        return $this;
    }

    /**
     * 加载 session saveHandler
     * @return $this
     * @throws
     */
    protected function loadSaveHandlerAndPath()
    {
        $this->setRealSavePath($this->setRealSaveHandler());
        return $this;
    }

    /**
     * 设置当前的 session realSaveHandler 并返回
     * @return string|SessionHandlerInterface
     */
    protected function setRealSaveHandler()
    {
        // 未发生变动
        if ($this->realSaveHandler) {
            return $this->realSaveHandler;
        }
        $handler = $this->getSaveHandler();
        // 新 handler 为对象
        if ($handler instanceof SessionHandlerInterface) {
            return $this->applySaveHandler($handler);
        }
        // 新 handler 为 string, 校验是否为原生 Handler 支持
        $nativeHandler = null;
        $lowerHandler = trim(strtolower($handler));
        if ('files' === $lowerHandler) {
            $nativeHandler = 'files';
        } elseif ('redis' === $lowerHandler && class_exists('\Redis')) {
            $nativeHandler = 'redis';
        } elseif ('memcache' === $lowerHandler || 'memcached' === $lowerHandler) {
            $nativeHandler = class_exists('\Memcached') ? 'memcached' : (class_exists('\Memcache') ? 'memcache' : null);
        }
        if ($nativeHandler) {
            return $this->applySaveHandler($nativeHandler);
        }
        // 新 handler 是 string, 校验是否可实例化为 SessionHandler 接口类
        if (class_exists($handler)) {
            $saveHandler = new $handler($this);
        } else {
            $lowerHandler = 'memcached' === $lowerHandler ? 'memcache' : $lowerHandler;
            if (class_exists($saveHandler = 'Tanbolt\\Session\\Handler\\'.ucfirst($lowerHandler))) {
                $saveHandler = new $saveHandler($this);
            }
        }
        if (!($saveHandler instanceof SessionHandlerInterface)) {
            throw new InvalidArgumentException("Cannot instance session handler '$handler' - session startup failed");
        }
        return $this->applySaveHandler($saveHandler);
    }

    /**
     * 应用 SaveHandler
     * @param string|SessionHandlerInterface $saveHandler string:原生 handler
     * @return string|SessionHandlerInterface
     */
    protected function applySaveHandler($saveHandler)
    {
        if (is_string($saveHandler)) {
            ini_set('session.save_handler', $saveHandler);
        } else {
            // 5.4.0 之后开始支持对象方式设置自定义 handler
            if (PHP_VERSION_ID >= 50400) {
                session_set_save_handler($saveHandler, $this->registerShutdown);
            } else {
                $open = [$saveHandler, 'open'];
                $close = [$saveHandler, 'close'];
                $read = [$saveHandler, 'read'];
                $write = [$saveHandler, 'write'];
                $destroy = [$saveHandler, 'destroy'];
                $gc = [$saveHandler, 'gc'];
                session_set_save_handler($open, $close, $read, $write, $destroy, $gc);
                if (!$this->hasRegister && $this->registerShutdown) {
                    $this->hasRegister = true;
                    register_shutdown_function([$this, '_registerShuntDownForOldPhp']);
                }
            }
        }
        return $this->realSaveHandler = $saveHandler;
    }

    /**
     * 针对低版本 php 的 shutdown 函数
     */
    protected function _registerShuntDownForOldPhp()
    {
        if ($this->registerShutdown) {
            session_commit();
        }
    }

    /**
     * 设置当前的 session realSavePath 并返回
     * @param string|SessionHandlerInterface $handler
     * @return string
     * @throws ErrorException
     */
    protected function setRealSavePath($handler)
    {
        // 未发生变动
        if (null !== $this->realSavePath) {
            return $this->realSavePath;
        }
        $savePath = $this->getSavePath();
        if (is_string($handler)) {
            // 当前 saveHandler 为 原生驱动, 校验 savePath
            $savePath = static::getNativePath($handler, $savePath);
            if (null === $savePath) {
                throw new ErrorException('Error session save_path for session handler: '.$handler);
            }
        } elseif (!is_string($savePath)) {
            // 当前 saveHandler 为自定义类, 但 savePath 不为 string
            $savePath = '';
        }
        return $this->applySavePath($savePath);
    }

    /**
     * 应用 savePath
     * @param $savePath
     * @return mixed
     */
    protected function applySavePath($savePath)
    {
        ini_set('session.save_path', $savePath);
        return $this->realSavePath = $savePath;
    }

    /**
     * 获取 native handler 的 path
     * @param string $handler
     * @param string|array $path
     * @return string|null
     */
    protected static function getNativePath(string $handler, $path)
    {
        if ('files' === $handler) {
            return is_string($path) ? (string) $path : null;
        }
        if (is_array($path)) {
            $nativePath = static::stringifyNativePath($path, $handler);
            foreach ($path as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $nativePath = ('' === $nativePath ? '' : ',') . static::stringifyNativePath($p, $handler);
            }
            return $nativePath;
        }
        return null;
    }

    /**
     * 将 memcache redis session path 配置从数组转为 原生 session handle 所需的字符串 path
     * $path = [host, path, weight,  [host, path, weight], ... ]
     * @param array $path
     * @param string $type
     * @return string
     */
    protected static function stringifyNativePath(array $path, string $type = 'memcache')
    {
        if (!isset($path['host'])) {
            return '';
        }
        $str = ('memcached' === $type ? '' : 'tcp://') . $path['host'] . ':';
        if (isset($path['port'])) {
            $str .= intval($path['port']);
        } else {
            $str .= 'redis' === $type ? 6379 : 11211;
        }
        if (isset($path['weight'])) {
            $str .= ('memcached' === $type ? ':' : '?weight=') . intval($path['weight']);
        }
        return $str;
    }

    /**
     * 设置本次 Http 请求携带的所有 cookie。
     *      默认清空下，session_start 后会发送 header, 若程序以 TCP 模式运行, tcp 进程内多次发送 header 会抛出异常，
     *      所以需自行发送 session cookie header, 自行发送需要判断请求 cookie 中是否包含 sessionId,
     *      是否需要发送该 Set-Cookie header，该方法用于简化这种流程，需在 session_start 前将所有 (array)cookie 作为参数进行设置。
     *
     * 设置方法：
     *
     * 1. 若设置 $cookies = false, 恢复为原生 session 逻辑，
     *    php 根据已设置的 "session.cookie_name" ini 从 $_COOKIE 获取会话 ID,
     *    并在 session_start 时根据 session.use_cookies 设置决定是否输出 Set-Cookie header
     *
     * 2. 若设置了 $cookies 为数组, 使用内置逻辑，
     *    Session 对象会根据已设置的 "session.cookie_name" 从 $cookies 数组中获取会话 ID,
     *    并会在 session_start 之前强制设置 session.use_cookies 为 0, 即不让 php 发送设置 Set-Cookie header,
     *    后续可通过 getResponseCookie() 的返回值来判断本次请求是否需要发送 Set-Cookie header
     * @param array|bool $cookies
     * @return $this
     */
    public function setRequestCookies($cookies)
    {
        if (false === $cookies) {
            // 恢复为原生状态
            if (null !== $this->useCookiesIni) {
                ini_set('session.use_cookies', $this->useCookiesIni);
                $this->useCookiesIni = null;
            }
            $this->customCookies = null;
        } else {
            // 使用自定义的 session cookie
            if (null === $this->useCookiesIni) {
                $this->useCookiesIni = $this->isIni('use_cookies');
                ini_set('session.use_cookies', false);
            }
            $this->customCookies = (array) $cookies;
        }
        return $this;
    }

    /**
     * 获取 Response 需要发送的 cookie
     * @param bool $asLine 是否返回字符串形式，默认返回数组形式
     * @return array|string|null
     */
    public function getResponseCookie(bool $asLine = false)
    {
        if (true !== $this->customCookies || empty($name = $this->getCookieName())) {
            return null;
        }
        $lifeTime = $this->getCookieLifetime();
        $cookie = [
            'name' => $name,
            'value' => $this->getId(),
            'Expires' => $lifeTime ? time() + $lifeTime : 0,
            'Path' => $this->getCookiePath(),
            'Domain' => $this->getCookieDomain(),
            'SameSite' => $this->getCookieSameSite(),
            'Priority' => 'High',
            'Secure' => $this->isCookieSecure(),
            'HttpOnly' => $this->isCookieHttpOnly(),
        ];
        if (!$asLine) {
            return $cookie;
        }
        $str = [$name.'='.$cookie['value']];
        unset($cookie['name'], $cookie['value']);
        foreach ($cookie as $key => $value) {
            if (empty($value)) {
                continue;
            }
            if ('Expires' === $key) {
                $value = gmdate(DATE_RFC7231, $value);
            }
            $str[] = $key.(true === $value ? '' : '='.$value);
        }
        return implode('; ', $str);
    }

    /**
     * @inheritDoc
     */
    public function setId(string $id)
    {
        session_id($id);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getId()
    {
        return session_id();
    }

    /**
     * @inheritDoc
     */
    public function isStart()
    {
        if (function_exists('session_status')) {
            return session_status() === PHP_SESSION_ACTIVE;
        }
        return $this->started;
    }

    /**
     * @inheritDoc
     */
    public function start(array $options = null)
    {
        // 此处 原生 session 会抛出一个 Notice 这里不再抛出
        if ($this->isStart()) {
            throw new RuntimeException('A session had already been started');
        }
        if ($options) {
            $this->setIni($options);
        }
        // 使用内置逻辑处理 session cookie
        if (is_array($this->customCookies)) {
            $name = empty($this->getId()) ? $this->getCookieName() : null;
            if ($name && isset($this->customCookies[$name])) {
                $this->setId($this->customCookies[$name]);
                $this->customCookies = null;
            } else {
                $this->customCookies = true;
            }
        }
        if ($this->loadSaveHandlerAndPath() && session_start()) {
            $this->oldSession = $_SESSION;
            return $this->started = true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function regenerate(bool $delete_old_session = false)
    {
        session_regenerate_id($delete_old_session);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function encode()
    {
        return $this->started ? session_encode() : '';
    }

    /**
     * @inheritDoc
     */
    public function decode(string $data)
    {
        session_decode($data);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function all()
    {
        return $this->started ? $_SESSION : [];
    }

    /**
     * @inheritDoc
     */
    public function has(string $name)
    {
        return $this->started && isset($_SESSION[$name]);
    }

    /**
     * @inheritDoc
     */
    public function get(string $name, $default = null)
    {
        return $this->started && isset($_SESSION[$name]) ? $_SESSION[$name] : $default;
    }

    /**
     * @inheritDoc
     */
    public function set(string $name, $value)
    {
        if ($this->started) {
            $_SESSION[$name] = $value;
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function remove(string $name)
    {
        if ($this->started) {
            unset($_SESSION[$name]);
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function clear()
    {
        session_unset();
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function reset()
    {
        if (PHP_VERSION_ID >= 50600) {
            session_reset();
        } elseif ($this->isStart()) {
            $_SESSION = $this->oldSession;
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function abort()
    {
        if (PHP_VERSION_ID >= 50600) {
            session_abort();
        } elseif ($this->isStart()) {
            $newSession = $_SESSION;
            $_SESSION = $this->oldSession;
            session_commit();
            $_SESSION = $newSession;
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function destroy()
    {
        if ($this->isStart()) {
            session_destroy();
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function close()
    {
        session_commit();
        $this->oldSession = $_SESSION;
        return $this;
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return $this->has($offset);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
        $this->set($offset, $value);
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset($offset)
    {
        $this->remove($offset);
    }

    /**
     * 返回 allColumn 字段数目
     * @return int
     */
    public function count()
    {
        return count($_SESSION);
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $_SESSION;
    }

    /**
     * 重置 Session 配置为初始化配置，
     * 在 tcp 守护进程方式运行时, 配合 setIni() 方法，可方便的将 Session 作为共享实例使用
     */
    public function __destruct()
    {
        // 自动提交保存 session
        if ($this->isStart()) {
            session_commit();
            session_id('');
        }
        // 重置基本属性
        $this->started = false;
        $this->customCookies = null;
        $this->oldSession = $_SESSION = [];
        $lifecycleOptions = $this->lifecycleOptions;
        $this->lifecycleOptions = [];
        if (null === $this->bootConfig) {
            return;
        }
        // 重置为初始化配置
        $handlerChanged = $pathChanged = false;
        foreach ($lifecycleOptions as $name => $val) {
            if ('save_handler' === $name) {
                $handlerChanged = true;
                $this->userSaveHandler = $this->bootConfig[$name];
            } elseif ('save_path' === $name) {
                $pathChanged = true;
                $this->userSavePath = $this->bootConfig[$name];
            } else {
                $this->setIni($name, $this->bootConfig[$name]);
            }
        }
        if ($handlerChanged) {
            $this->registerShutdown = $this->bootShutDown;
            $this->applySaveHandler($this->bootSaveHandler);
        }
        if ($pathChanged) {
            $this->applySavePath($this->bootSavePath);
        }
    }
}
