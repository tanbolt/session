<?php
namespace Tanbolt\Session\Fixtures;

use SessionHandlerInterface;

class SessionHandler implements SessionHandlerInterface
{
    protected $path = '';
    protected $tmp = [];

    public function open($savePath, $sessionName)
    {
        $this->path = $savePath;
        return true;
    }

    public function close()
    {
        return true;
    }

    public function read($sessionId)
    {
        if (isset($this->tmp[$sessionId])) {
            return (string) $this->tmp[$sessionId];
        }
        return '';
    }

    public function write($sessionId, $data)
    {
        $this->tmp[$sessionId] = $data;
        return true;
    }

    public function destroy($sessionId)
    {
        unset($this->tmp[$sessionId]);
        return true;
    }

    public function gc($maxLifetime)
    {
        return true;
    }

    public function getTmp($key = null)
    {
        return null === $key ? $this->tmp : ($this->tmp[$key] ?? null);
    }

    public function getPath()
    {
        return $this->path;
    }
}
