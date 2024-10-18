<?php
namespace Tanbolt\Session;

use SessionHandlerInterface;

class Store implements SessionHandlerInterface
{
    protected $session;
    protected $openCall;
    protected $closeCall;
    protected $readCall;
    protected $writeCall;
    protected $destroyCall;
    protected $gcCall;

    public function __construct(
        SessionInterface $session,
        callable $open,
        callable $close,
        callable $read,
        callable $write,
        callable $destroy,
        callable $gc
    ) {
        $this->session = $session;
        $this->openCall = $open;
        $this->closeCall = $close;
        $this->readCall = $read;
        $this->writeCall = $write;
        $this->destroyCall = $destroy;
        $this->gcCall = $gc;
    }

    public function open($save_path, $session_id)
    {
        $save_path = $this->session->getSavePath();
        return call_user_func($this->openCall, $save_path, $session_id);
    }

    public function close()
    {
        return call_user_func($this->closeCall);
    }

    public function read($session_id)
    {
        return call_user_func($this->readCall, $session_id);
    }

    public function write($session_id, $session_data)
    {
        return call_user_func($this->writeCall, $session_id, $session_data);
    }

    public function destroy($session_id)
    {
        return call_user_func($this->destroyCall, $session_id);
    }

    public function gc($maxLifetime)
    {
        return call_user_func($this->gcCall, $maxLifetime);
    }
}
