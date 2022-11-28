<?php

namespace Src;
use Src\WebSocketServer;
use Src\IMessage;

class ChatServer extends WebSocketServer
{
    // IT IS A CHAT USER LEVEL LOGIC
    private Chat $chat;

    public function __construct($ws_server_sock, Chat $chat_app)
    {
        parent::__construct($ws_server_sock);
        $this->chat = $chat_app;
    }

    function on_opened_websocket_connection(string $clientID)
    {
        $online_list_message = $this->chat->build_online_list_message($clientID);
        $this->chat->init_user($clientID);
        $this->send_message($clientID, $online_list_message);
    }

    private function greet(string $senderID)
    {
        if ($this->chat->need_abort($senderID) || !$this->chat->is_auth($senderID)) return;

        $greet_msg = $this->chat->build_greet_message($senderID);
        $this->send_message($senderID, $greet_msg);
    }

    private function bye(string $senderID)
    {
        if ($this->chat->need_abort($senderID) || !$this->chat->is_auth($senderID)) return;

        $farewell_msg = $this->chat->build_farewell_message($senderID);
        $this->send_message($senderID, $farewell_msg);
    }

    function on_closed_websocket_connection(string $clientID)
    {
        $this->bye($clientID);
        $this->chat->destroy_user($clientID);
    }

    function on_message(string $senderID, string $message, string $type, bool $fin)
    {
        if ( !$this->chat->is_auth($senderID)) {
            $this->chat->verify_access_token($senderID, $message);
            $this->greet($senderID);
        }
        else {
            $msg = $this->chat->build_transit_message($senderID, $message, $type);
            $this->send_message($senderID, $msg);
        }
    }

    private function send_message(string $senderID, Message $message_obj)
    {
        $message = $message_obj->represent();
        $receivers = $message_obj->get_receivers();

        if ($this->chat->need_abort($senderID)) {
            $this->send_close_frame_to_client($senderID, 3001, "Authenticate error");
            echo "need abORT\n";
        }
        $this->send_message_to_all_client_of($senderID,$receivers, $message);
    }

    function on_close(string $clientID, string $close_msg)
    {
        // while deprecated
        // TODO: Implement on_close() method.
    }
}