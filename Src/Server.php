<?php
namespace Src;
use Src\Chat;
use Src\ChatServer;
use Src\ServerConfig as SC;
mb_internal_encoding("UTF-8");
class Server
{
    private array $config;

    public function __construct(array $chat_config)
    {
        $this->config = $chat_config;
    }

    // For integration with yii
    private function _start_tcp_service_server() {}

    private function _create_ws_server() {
        if (false === $ws_server_sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) {
            exit(socket_strerror(socket_last_error()));
        }
        if (false === socket_set_option($ws_server_sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
            exit(socket_strerror(socket_last_error($ws_server_sock)));
        }
        if (false === socket_bind($ws_server_sock, SC::WS_ADDRESS, SC::WS_PORT)) {
            exit(socket_strerror(socket_last_error($ws_server_sock)));
        }
        if (false === socket_listen($ws_server_sock, SC::WS_BACKLOG)) {
            exit(socket_strerror(socket_last_error($ws_server_sock)));
        }
        return $ws_server_sock;
    }

    public function run()
    {
        $is_pid = file_exists($this->config['pid']);
        if ($is_pid) {
            $pid = file_get_contents($this->config['pid']);
            if (posix_getpgid($pid)) {
                die("already started\r\n");
            } else {
                unlink($this->config['pid']);
            }
        }

        $ws_server_sock = $this->_create_ws_server();
        $chat_app = new Chat($this->config);
        $ws_chat_server = new ChatServer($ws_server_sock, $chat_app);

        file_put_contents($this->config['pid'], posix_getpid());

        $ws_chat_server->start_select_loop();
    }

    public function stop() {
        $is_pid = file_exists($this->config['pid']);
        if ($is_pid) {
            $pid = file_get_contents($this->config['pid']);
            if ($pid) {
                posix_kill($pid, SIGTERM);
                for ($i=0;$i=10;$i++) {
                    sleep(1);

                    if (!posix_getpgid($pid)) {
                        unlink($this->config['pid']);
                        return;
                    }
                }
                die("don't stopped\r\n");
            }
        }
        else {
            die("already stopped\r\n");
        }
    }

    public function rerun() {
        $pid = @file_get_contents($this->config['pid']);
        if ($pid) {
            $this->stop();
        }

        $this->run();
    }

}