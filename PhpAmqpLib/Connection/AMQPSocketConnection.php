<?php

namespace PhpAmqpLib\Connection;

use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPConnectionException;
use PhpAmqpLib\Helper\MiscHelper;
use PhpAmqpLib\Wire\AMQPWriter;
use PhpAmqpLib\Wire\AMQPSocketReader;

class AMQPSocketConnection extends AMQPConnection
{
    public function __construct($host, $port,
                                $user, $password,
                                $vhost="/",$insist=false,
                                $login_method="AMQPLAIN",
                                $login_response=null,
                                $locale="en_US",
                                $connection_timeout = 3,
                                $read_write_timeout = 3)
    {
        $this->construct_params = func_get_args();

        if ($user && $password) {
            $login_response = new AMQPWriter();
            $login_response->write_table(array("LOGIN" => array('S',$user),
                                               "PASSWORD" => array('S',$password)));
            $login_response = substr($login_response->getvalue(),4); //Skip the length
        } else {
            $login_response = null;
        }

        $d = self::$LIBRARY_PROPERTIES;
        while (true) {
            $this->channels = array();
            // The connection object itself is treated as channel 0
            AbstractChannel::__construct($this, 0);

            $this->channel_max = 65535;
            $this->frame_max = 131072;

            $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            if (!socket_connect($this->sock, $host, $port)) {
                $errno = socket_last_error($this->sock);
                $errstr = socket_strerror($errno);
                throw new \Exception ("Error Connecting to server($errno): $errstr ");
            }

            socket_set_block($this->sock);
            socket_set_option($this->sock, SOL_TCP, TCP_NODELAY, 1);
            socket_set_option($this->sock, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $read_write_timeout, 'usec' => 0));
            socket_set_option($this->sock, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $read_write_timeout, 'usec' => 0));

            $this->input = new AMQPSocketReader(null, $this->sock);

            $this->write(self::$AMQP_PROTOCOL_HEADER);
            $this->wait(array("10,10"));
            $this->x_start_ok($d, $login_method, $login_response, $locale);

            $this->wait_tune_ok = true;
            while ($this->wait_tune_ok) {
                $this->wait(array(
                                "10,20", // secure
                                "10,30", // tune
                            ));
            }

            $host = $this->x_open($vhost,"", $insist);
            if (!$host) {
                return; // we weren't redirected
            }

            // we were redirected, close the socket, loop and try again
            $this->close_socket();
        }
    }

    protected function close_socket()
    {
        if ($this->debug) {
          MiscHelper::debug_msg("closing socket");
        }

        if (is_resource($this->sock)) {
          socket_close($this->sock);
        }
        $this->sock = null;
    }

    protected function write($data)
    {
        if ($this->debug) {
          MiscHelper::debug_msg("< [hex]:\n" . MiscHelper::hexdump($data, $htmloutput = false, $uppercase = true, $return = true));
        }

        $len = strlen($data);

        while (true) {
            $sent = socket_write($this->sock, $data, $len);
            if ($sent === false) {
                throw new \Exception ("Error sending data");
            }
            // Check if the entire message has been sented
            if ($sent < $len) {
                // If not sent the entire message.
                // Get the part of the message that has not yet been sented as message
                $data = substr($data, $sent);
                // Get the length of the not sented part
                $len -= $sent;
            } else {
                break;
            }
        }
    }
}
