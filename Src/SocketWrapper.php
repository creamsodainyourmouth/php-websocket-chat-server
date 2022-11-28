<?php

namespace Src;

class SocketWrapper
{
    static function Socket_send($socket, string $data, int $length, int $flags)
    {
        if (false === is_resource($socket)) return false;
        $bytes = socket_send($socket, $data, $length, $flags);
        if (false === $bytes) {
            echo "socket_send error: " .
                socket_strerror(socket_last_error($socket)) . "\n";
            return false;
        }
        return $bytes;
    }

    static function Socket_shutdown($socket, int $mode = 2)
    {
        if (is_resource($socket)) {
            socket_shutdown($socket, $mode);
        }
    }

    static function Socket_close($socket)
    {
        if (is_resource($socket)) {
            socket_close($socket);
        }
    }
}