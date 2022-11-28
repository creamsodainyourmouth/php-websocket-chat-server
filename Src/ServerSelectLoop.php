<?php

namespace Src;
use Src\ServerConfig as SC;
use Src\WSDefender;
use Src\SocketWrapper as SW;
use Src\WebSocketServer;

abstract class ServerSelectLoop
{
    // IT IS A SOCKET LEVEL LOGIC

    // 8640 = (MTU (1500 bytes) - IP-headers (20 bytes) - TCP-headers (20 bytes) - TCP-options (~20 bytes)) * 6
    // one cyrillic character is 2 byte (UTF-8), then 4320 is an estimated maximum message length.
    // It's only for application level sizes.
    // The amount of actual received data by TCP at a time may be less.
    // but it.. just need control the TCP-sendbuffer size
    const RECV_BUFFER_SIZE_TICK = 8640;
    const SEND_BUFFER_SIZE_TICK = 8640;
    const BUFFER_SIZE = 65536;

    const SOCKET_RECV_ERROR = 2000;
    const SOCKET_SEND_ERROR = 2001;
    const SOCKET_TCP_FIN_RECVD = 2002;
    const SOCKET_RECV_OK = 2003;
    const SOCKET_SEND_OK = 2004;


    // clients data structure
    const SOCKET = 0;
    const SEND_BUFF = 1;
    const RECV_BUFF = 2;
    const LAST_BYTES_RECVD = 3;
    const HANDSHAKED = 4;
    const WS_CONNECTION_STATE = 5;
    const SEND_BUFF_CONTAINS = 6;
    const RECV_BUFF_CONTAINS = 7;
    const WS_CLOSE_IS_SENT = 8;
    const WS_CLOSE_IS_RECVD = 9;
    const MAX_KEY_OF_CLIENT_DATA = 9;
    /*

        array $clients_connection as [
              string `clientID` => array [
                  0 => resource,      client socket
                  1 => string,      receive buffer
                  2 => string       send buffer
                  4 => bool     need handshaked
                  3 => int      last time bytes received
              ]
        ]

    */
    protected int $clients_amount = 0;
    protected $ws_server_sock = null;
    protected $tcp_service_server_sock = null;
    private array $clients_socks = [];
    protected array $clients = [];

    // clients who need to send a CLOSE WS frame
    protected array $ws_closures_clients = [];


    private function create_timer() {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0,$pair);

        $pid = pcntl_fork();

        if ($pid == -1) {
            die("error: pcntl_fork\r\n");
        } elseif ($pid) { // parent
            socket_close($pair[0]);
            return $pair[1];// one of the pair will be in the parent
        } else { //child process
            socket_close($pair[1]);
            $parent = $pair[0];// second of the pair will be in the child

            while (true) {
                // TODO: If parent process emergency closed, how kill child?
                $is_pid = file_exists('/tmp/chat.pid');
                if (! $is_pid) die('parent error');
                if (is_resource($parent)) {
                    socket_send($parent, '1', 1, 0);
                    usleep(10 * 1000000);
                }
                else {
                    die('parent error');
                }
            }
        }
    }

    public function start_select_loop() {

        $timer = $this->create_timer();

        while (true) {
            // timer?
            $read = $write = $except = [];

            if (is_resource($this->ws_server_sock)) $read[] = $this->ws_server_sock;
            if (is_resource($this->tcp_service_server_sock)) $read[] = $this->tcp_service_server_sock;
            if (is_resource($timer)) $read[] = $timer;

            foreach ($this->clients as $clientID => $client_conn) {
                $read[$clientID] = $client_conn[self::SOCKET];
                // send buffer of this client is not empty
                if ($client_conn[self::SEND_BUFF]) {
                    $write[$clientID] = $client_conn[self::SOCKET];
                }
            }

            $except = $read;

            $n_ready = socket_select($read, $write, $except, null);

            if (false === $n_ready) {
                exit(socket_strerror(socket_last_error()));
            }

            foreach ($read as $clientID => $sock) {
                if ($sock === $this->ws_server_sock) {
                    if (count($this->clients) < SC::MAX_CLIENTS) {
                        if (false === $client = socket_accept($this->ws_server_sock)) {
                            echo "socket_accept error: " . socket_strerror(socket_last_error($this->ws_server_sock)) . "\n";
                        }
                        $new_clientID = WSDefender::generate_clientID($client);
                        $this->init_client_data($new_clientID, $client);
                        $this->on_opened_TCP_connection($new_clientID);
                    }
                    else {
                        echo "Reached the limit of simultaneous connections\n";
                    }
                }
                // Local message from another PHP Script (service?)
                elseif ($sock === $this->tcp_service_server_sock) {}
                elseif ($sock === $timer) {
                    $n = socket_recv($timer, $tick, self::RECV_BUFFER_SIZE_TICK, 0);
                    $this->on_need_ping();
                }

                // New data from client socket
                else {
                    $recv_status = $this->recv_data($clientID, $sock);
                    if ($recv_status === self::SOCKET_RECV_OK) {
                        if (false === $this->on_new_data($clientID)) {
                            // WebSocket Connection CLOSED
                            $this->destroy_client_data($clientID);
                        }
                    }

                    elseif ($recv_status === self::SOCKET_TCP_FIN_RECVD) {
                        $this->close_TCP_connection($clientID);
                        $this->on_closed_TCP_connection($clientID);
                        $this->remove_client($clientID);
                    }
                    else {
                        $this->close_TCP_connection($clientID);
                        $this->remove_client($clientID);
                    }
                }
            }

            // Are need to delete client from this?
            foreach ($write as $clientID => $sock) {
                $send_status = $this->send_data($clientID, $sock);
                if ($send_status === self::SOCKET_SEND_OK) {
                    continue;
                }
                elseif ($send_status === self::SOCKET_SEND_ERROR) {
                    $this->on_error_socket_send($clientID);
                    $this->close_TCP_connection($clientID);
                    $this->remove_client($clientID);
                }
            }

            foreach ($except as $clientID => $sock) {
                echo "Select exception with client $clientID\n";
                $this->on_select_exception($clientID);
                $this->remove_client($clientID);
            }

        }
    }


    private function recv_data(string $clientID, $client_socket): int
    {
        if (isset($this->clients[$clientID]) && is_resource($client_socket)) {
            // TODO: RECV_BUFFER_SIZE_TICK need more?
            $bytes_recvd = socket_recv($client_socket, $data, self::RECV_BUFFER_SIZE_TICK, 0);
            if ($bytes_recvd === false) {
                echo "socket_recv error: " . socket_strerror(socket_last_error($client_socket)) . "\n";
                // return self::MSG_RECVD_STATUS_ERROR;
                return self::SOCKET_RECV_ERROR;
            } elseif ($bytes_recvd === 0) {
                // TCP-FIN segment received
                // return self::MSG_RECVD_STATUS_TCP_FIN;
                return self::SOCKET_TCP_FIN_RECVD;
            }

            $this->clients[$clientID][self::RECV_BUFF_CONTAINS] += $bytes_recvd;
            // TODO: Are need limits RECV_BUFF ?
            $this->clients[$clientID][self::RECV_BUFF] .= $data;
            // return self::MSG_RECVD_STATUS_OK;
            return self::SOCKET_RECV_OK;
        }
        return self::SOCKET_TCP_FIN_RECVD;
    }

    private function send_data(string $clientID, $sock): int
    {
        if (isset($this->clients[$clientID]) && is_resource($sock)) {
            // it's an amount of bytes received by TCP
            $bytes_sent = socket_send(
                $sock, $this->clients[$clientID][self::SEND_BUFF], self::SEND_BUFFER_SIZE_TICK, 0
            );
            if ($bytes_sent === false) {
                echo "socket_send error: " . socket_strerror(socket_last_error($sock)) . "\n";
                return self::SOCKET_SEND_ERROR;
            }

            $this->clients[$clientID][self::SEND_BUFF] = substr(
                $this->clients[$clientID][self::SEND_BUFF], $bytes_sent
            );
            $this->clients[$clientID][self::SEND_BUFF_CONTAINS] -= $bytes_sent;
            return self::SOCKET_SEND_OK;
        }
        return self::SOCKET_SEND_ERROR;
    }


    protected function get_sock_by_ID(string $clientID)
    {
        return $this->clients[$clientID][self::SOCKET];
    }

    private function init_client_data(string $clientID, $client_socket): void
    {
        $this->clients[$clientID] = [
            self::SOCKET => $client_socket,
            self::SEND_BUFF => "",
            self::RECV_BUFF => "",
            self::SEND_BUFF_CONTAINS => 0,
            self::RECV_BUFF_CONTAINS => 0
            ];
    }


    protected function close_TCP_connection(string $clientID)
    {
        SW::Socket_close($this->clients[$clientID][self::SOCKET]);
    }

    private function destroy_client_data(string $clientID)
    {
        // $this->clients[$clientID][0] - is a socket, do not need to delete there
        for ($i = 1; $i <= self::MAX_KEY_OF_CLIENT_DATA; ++$i) {
            $this->clients[$clientID][$i] = null;
        }
    }

    private function remove_client(string $clientID): void
    {
        unset($this->clients[$clientID]);
    }


    protected function is_client_sent_TCP_FIN(string $clientID): bool
    {
        if (0 === socket_recv($this->get_sock_by_ID($clientID), $data, 1, MSG_PEEK)) {
            return true;
        }
        return false;
    }

    protected function send_all_at_once(string $clientID, string $data, int $data_length): bool
    {
        do {
            $bytes_sent = SW::Socket_send(
                $this->clients[$clientID][self::SOCKET], $data, $data_length, 0
            );
            if (false === $bytes_sent) {
                echo "send_all_at_once(): error with client -> $clientID\n";
            }

            $data_length -= $bytes_sent;
            $data = substr($data, $bytes_sent);
        }
        while ($bytes_sent > 0);
        return true;
    }



    abstract function on_opened_TCP_connection(string $clientID);
    
    abstract function on_closed_TCP_connection(string $clientID);

    abstract function on_new_data(string $clientID);

    abstract function on_select_exception(string $clientID);

    abstract function on_error_socket_send(string $clientID);

    abstract function on_need_ping();


}