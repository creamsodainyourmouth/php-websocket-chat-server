<?php

namespace Src;

class WSDefender
{
    public static function generate_clientID($client_sock): string
    {
        // (int) SHOULD NOT be used with objects (php8 has object type Socket)
        return base64_encode(random_bytes(12)) . (int)$client_sock;
    }
}
