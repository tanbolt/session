<?php
namespace Tanbolt\Session\Handler;

class Memcache implements \SessionHandlerInterface
{
    public function open($save_path, $session_id)
    {

    }

    public function write($session_id, $session_data)
    {

    }

    public function read($session_id)
    {

    }

    public function destroy($session_id)
    {

    }

    public function close()
    {

    }

    public function gc($maxlifetime)
    {

    }

    // 可选函数 (如果未实现，不要提供)
    // public function create_sid(){}
}
