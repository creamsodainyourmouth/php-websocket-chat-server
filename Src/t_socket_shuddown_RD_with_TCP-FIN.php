<?php

$listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($listener, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($listener, "127.0.0.1", 8111);
socket_listen($listener, 5);

$clients = [];
$c = 0;

while (true) {
    $read = $clients;
    $read[] = $listener;

    $write = $except = null;

    echo "Wait for select!\n";
//    print_r($read);
//    print_r($clients);
    sleep(4);
    echo "end sleep\n";
    $n = socket_select($read, $write, $except, null);
    echo "Go";

    foreach ($read as $sock) {
        if ($sock === $listener) {
            echo "New conn!\n";
            $client = socket_accept($sock);
            $clients[] = $client;


        }
        else {


            $mess = socket_recv($sock, $data, 100, 0);
            if ($mess === 0) {
                echo "TCP-FIN RECVD!\n";
                sleep(5);
//                socket_close($sock);
            }
            elseif ($mess === false) {
                echo "Error socket\n";
            }
            else {
                if ($c > 0) {
                    socket_shutdown($sock, 0);
                    socket_send($sock, "close", 3, 0);
                    socket_shutdown($sock, 1);
                    socket_close($sock);
                }
                ++$c;
                echo "New mess length of [$mess] is ->> $data \n";
            }
        }
    }
}
