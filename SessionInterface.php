<?php
namespace Tanbolt\Session;

use SessionHandlerInterface;

/**
 * Interface SessionInterface
 * @package Tanbolt\Session
 */
interface SessionInterface
{
    /**
     * 设置 和 session 相关的 ini
     * @param string|array $name
     * @param ?string|bool $value
     * @return static
     */
    public function setIni($name, $value = null);

    /**
     * 获取 和 session 相关的 ini
     * @param string $name
     * @param mixed $default
     * @return string
     */
    public function getIni(string $name, $default = false);

    /**
     * 通过 string 或 SessionHandlerInterface 对象 设置 session 驱动器
     * @param string|SessionHandlerInterface $handler SessionHandlerInterface 对象 || class 名 || 原生支持的 sessionHandle
     * @param bool $registerShutdown
     * @return static
     * @link https://www.php.net/manual/zh/function.session-set-save-handler.php
     */
    public function setSaveHandler($handler, bool $registerShutdown = true);

    /**
     * 通过 method 设置 session 驱动器
     * @param callable $open
     * @param callable $close
     * @param callable $read
     * @param callable $write
     * @param callable $destroy
     * @param callable $gc
     * @return static
     */
    public function setSaveMethod(
        callable $open,
        callable $close,
        callable $read,
        callable $write,
        callable $destroy,
        callable $gc
    );

    /**
     * 获取自定义的 session 驱动器，若未设置，则返回 php.ini 中的设置
     * @return SessionHandlerInterface|string|null
     */
    public function getSaveHandler();

    /**
     * 设置 session saveModule 或 saveHandler 的参数
     *  1. 若使用原生 session handler, path 为 string, 但 redis/memcache 也支持 array, 内部会自动转 string；
     *  2. 若使用自定义的 session method, path 可以为任意变量
     *  3. 若使用自定义的 session handler, path 可以为任意变量，但需要按照以下规则
     *
     *      class SessionHandler implements \SessionHandlerInterface
     *      {
     *              protected $session;
     *              public function __construct(SessionInterface $session){
     *                  $this->session = $session;
     *              }
     *              public function open($save_path, $session_id) {
     *                  // 若 save_path 不是 string, 需通过 session 来获取, 而不是直接使用参数
     *                  $save_path = $this->session->getSavePath();
     *                  return call_user_func($this->openCall, $save_path, $session_id);
     *              }
     *      }
     * @param mixed $path
     * @return static
     * @link http://php.net/manual/zh/function.session-save-path.php
     */
    public function setSavePath($path);

    /**
     * 获取自定义的 session 驱动器参数，若未设置，则返回 php.ini 中的设置
     * @return mixed
     */
    public function getSavePath();

    /**
     * 设置当前请求的 cookie, 以便 Session 对象可以提取 sessionId;
     * 在开启 session.use_cookies ini 时，PHP 默认的 session 是自动提取的,
     * 这里预留一个接口, 以便在未开启 session.use_cookies 时 Session 对象可以自行处理
     * @param mixed $cookies
     * @return static
     */
    public function setRequestCookies($cookies);

    /**
     * 获取 Response 需要发送的 cookie;
     * 在开启 session.use_cookies ini 时，PHP 默认 session 是自动设置的, 返回 null 即可
     * 但若关闭了该配置，需返回需要发送的 cookie (数组 或 header string)
     * @param bool $asLine
     * @return array|string|null
     */
    public function getResponseCookie(bool $asLine = false);

    /**
     * 设置 session id
     * @param string $id
     * @return static
     * @link http://php.net/manual/zh/function.session-id.php
     */
    public function setId(string $id);

    /**
     * 获取 session id
     * @return string
     */
    public function getId();

    /**
     * 判断 session 是否启动
     * > session_status() 返回值有三种可能, 其中 PHP_SESSION_DISABLED 是本接口无需考虑的
     *   剩下两种状态值, 所以直接返回布尔值
     * @return bool
     * @link http://php.net/manual/zh/function.session-status.php
     */
    public function isStart();

    /**
     * 启动 Session
     * @param array|null $options
     * @return bool
     * @link http://php.net/manual/zh/function.session-start.php
     */
    public function start(array $options = null);

    /**
     * 使用新生成的会话 ID 更新现有会话 ID
     * @param bool $delete_old_session 是否删除旧 session 文件
     * @return static
     * @link http://php.net/manual/zh/function.session-regenerate-id.php
     */
    public function regenerate(bool $delete_old_session = false);

    /**
     * 返回当前 $_SESSION 编码后的 string
     * @return string
     * @link http://php.net/manual/zh/function.session-encode.php
     */
    public function encode();

    /**
     * 解码数据,并且使用解码后的数据累加到 $_SESSION 全局变量
     * @param string $data
     * @return static
     * @link http://php.net/manual/zh/function.session-decode.php
     */
    public function decode(string $data);

    /**
     * 获取所有 session
     * @return array
     */
    public function all();

    /**
     * 是否含有指定的 session
     * @param string $name
     * @return bool
     */
    public function has(string $name);

    /**
     * 获取指定的 session 的值
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function get(string $name, $default = null);

    /**
     * 设置指定的 session 值
     * @param string $name
     * @param mixed $value
     * @return static
     */
    public function set(string $name, $value);

    /**
     * 清除指定的 session 值
     * @param string $name
     * @return static
     */
    public function remove(string $name);

    /**
     * 清空所有已经设置的 $_SESSION
     * @return static
     * @link https://php.net/manual/en/function.session-unset.php
     */
    public function clear();

    /**
     * 重置会话 $_SESSION 为上次保存的数据
     * @return static
     * @link http://php.net/manual/zh/function.session-reset.php
     */
    public function reset();

    /**
     * 无视 start 之后的 $_SESSION 改变, 中断会话，下次请求获取的 session 仍为上次请求所设置的。
     * 但本次请求，仍然可以使用已获取或已设置的 $_SESSION，$_SESSION 仅相当于一个普通的 php 数组，请求结束后不会保存
     * @return static
     * @link http://php.net/manual/zh/function.session-abort.php
     */
    public function abort();

    /**
     * 销毁所有会话数据，并删除服务端对应文件，下次 start 之后获取到的 session 为空
     * @return static
     * @link http://php.net/manual/zh/function.session-destroy.php
     */
    public function destroy();

    /**
     * 关闭并合并本次已设置 session 值到服务器保存文件中，
     * 默认 registerShutdown=true, 会在本次请求结束后自动合并到服务器文件
     * @return static
     * @link http://php.net/manual/zh/function.session-write-close.php
     */
    public function close();
}
