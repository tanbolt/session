<?php
namespace Tanbolt\Session\Handler;

class Memory implements \SessionHandlerInterface
{
    /**
     * @var mixed
     */
    private $path;

    /**
     * @var string
     */
    private $name;

    /**
     * @var array
     */
    private $store = [];

    // 打开 session, 缓存设置值, 实际使用中用处不大, 方便单元测试
    public function open($save_path, $name)
    {
        $this->path = $save_path;
        $this->name = $name;
        return true;
    }

    public function write($session_id, $session_data)
    {
        $this->store[$session_id] = $session_data;
        return true;
    }

    public function read($session_id)
    {
        return $this->store[$session_id] ?? '';
    }

    public function destroy($session_id)
    {
        unset($this->store[$session_id]);
        return true;
    }

    public function close()
    {
        return true;
    }

    public function gc($maxLifetime)
    {
        return true;
    }

    // 可选函数 (如果未实现，不要提供)
    // public function create_sid(){}

    // 用于测试
    public function getPath()
    {
        return $this->path;
    }

    public function getName()
    {
        return $this->name;
    }
}
