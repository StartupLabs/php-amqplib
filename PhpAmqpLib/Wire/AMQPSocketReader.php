<?php

namespace PhpAmqpLib\Wire;

class AMQPSocketReader extends AMQPReader
{
    public function __construct($str, $sock=null)
    {
        $this->str = $str;
        if ($sock !== null) {
            $this->sock = $sock;
        } else {
            $this->sock = null;
        }
        $this->offset = 0;

        $this->bitcount = $this->bits = 0;

        if(((int) 4294967296)!=0)
            $this->is64bits = true;
        else
            $this->is64bits = false;

        if(!function_exists("bcmul"))
            throw new \Exception("'bc math' module required");

        $this->buffer_read_timeout = 5; // in seconds
    }

    public function close()
    {
        if ($this->sock) {
            socket_close($this->sock);
        }
    }

    protected function rawread($n)
    {
        if ($this->sock) {
            $res = '';
            $read = 0;

            $buf = socket_read($this->sock, $n);
            while ($read < $n && $buf !== '') {
                $read += strlen($buf);
                $res .= $buf;
                $buf = socket_read($this->sock, $n - $read);
            }

            if (strlen($res)!=$n) {
                throw new \Exception("Error reading data. Received " .
                                     strlen($res) . " instead of expected $n bytes");
            }

            $this->offset += $n;
        } else {
            if(strlen($this->str) < $n)
                throw new \Exception("Error reading data. Requested $n bytes while string buffer has only " .
                                     strlen($this->str));
            $res = substr($this->str,0,$n);
            $this->str = substr($this->str,$n);
            $this->offset += $n;
        }

        return $res;
    }
}
