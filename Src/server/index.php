<?php

namespace Src;
use Src\Server;

if (empty($argv[1]) || !in_array($argv[1], array('run', 'stop', 'rerun'))) {
    die("need parameter (run|stop|rerun)\r\n");
}


require_once __DIR__ . "/../../vendor/autoload.php";

$config = require_once __DIR__ . "/../config/chat.php";

$server = new Server($config);

call_user_func([$server, $argv[1]]);



