<?php

namespace Src;
use Src\ControlMessageFactory;
use Src\TextMessageFactory;
use Src\IMessage;
use Src\JWT;


class Chat
{
    const USER_ROLE = 0;
    const ANON_ROLE = 1;

    private array $greetings = [
        "Кто-то зашёл в чат..", "Присоединился новый пользователь .", "users += 1.",
        "Туки-туки, а я тута!"
    ];

    private array $farewells = [
        "А кто-то попрощался..", "Пользователь покинул чат.", "Пользователь покинул чат, так ничего и не сказав.",
        "users -= 1.", "Ну всё, давай, я пошёл."
    ];

    const JOINT_CHAT_ID = 0;

    private array $config;

    private array $online_users_list = [];
    private array $greet_chat = [];
    private array $random_chats = [];
    private JWT $jwt;

    /**
     * @throws \MiladRahimi\Jwt\Exceptions\InvalidKeyException
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->jwt = new JWT($this->config['jwt-secret']);
    }

    public function need_abort(string $senderID): bool
    {
        return $this->online_users_list[$senderID]['abort'] === true;
    }


    private function add_user_to_online_list(string $clientID)
    {
        $this->online_users_list[$clientID] = [
            "user" => null,
            "auth" => false,
            "abort" => false,
            "in_join_chat" => true,
            "in_private_chat" => false
        ];
    }

    public function remove_user_from_online_list(string $clientID)
    {
        unset($this->online_users_list[$clientID]);

    }

    private function add_user_to_greet_chat(string $clientID)
    {
        $this->greet_chat[$clientID] = [
            "visit_time" => time(),
            "leave_time" => null,
            "msg_send" => 0
        ];
    }

    public function destroy_user(string $clientID)
    {
        $this->remove_user_from_greet_chat($clientID);
        $this->remove_user_from_online_list($clientID);
    }

    private function remove_user_from_greet_chat(string $clientID)
    {
        unset($this->greet_chat[$clientID]);
    }


    public function build_greet_message(string $senderID): Message
    {
        $msg = JointMessageFactory::create_message(
            $senderID,
            $this->get_online_users_IDs(),
            $this->greetings[array_rand($this->greetings)],
            Message::GREET_MSG_TYPE
        );
        $msg->add_user_data(
            [
                'id' => $senderID,
//                'username' => $this->online_users_list[$senderID]['user']['username']
            ]
        );
        return $msg;
    }

    public function build_farewell_message(string $senderID): Message
    {
        return JointMessageFactory::create_message(
            $senderID,
            $this->get_online_users_IDs(),
            $this->farewells[array_rand($this->farewells)],
            Message::FAREWELL_MSG_TYPE
        );
    }

    public function get_online_users_IDs(): array
    {
        return array_keys($this->online_users_list);
    }

    protected function get_online_users()
    {

    }

    public function get_online_users_list(): array
    {
        $users = [];
        foreach ($this->online_users_list as $userID => $user_data) {
            if ( !$user_data['auth']) continue;
            $user = [];
            $user['id'] = $userID;
            $user['username'] = $user_data['user']['username'];
            $users[] = $user;
        }
        return $users;
    }

    public function init_user(string $clientID)
    {
        $this->add_user_to_online_list($clientID);
        $this->add_user_to_greet_chat($clientID);
    }

    public function is_auth(string $clientID): bool
    {
        return $this->online_users_list[$clientID]['auth'] === true;
    }

    public function build_transit_message(string $senderID, string $msg_data): Message
    {
        $parsed = json_decode($msg_data, true);

        switch ($parsed['type']) {
            case Message::JOINT_MSG_TYPE:
                if ($parsed['typing']) {
                    return new TypingMessage(
                        $senderID,
                        $this->get_online_users_IDs(),
                        $parsed['body']
                    );
                }
                return TextMessageFactory::create_message(
                    $senderID,
                    $this->get_online_users_IDs(),
                    $parsed['body']
                );

            case Message::PRIVATE_MSG_TYPE:
                $receiver = [$parsed['receiver']];
                return PrivateMessageFactory::create_message(
                    $senderID,
                    $receiver,
                    $parsed['body']
                );
        }
    }

    public function build_online_list_message(string $senderID): Message
    {
        $msg = ControlMessageFactory::create_message(
            $senderID,
            [$senderID],
            "",
            Message::ONLINE_LIST_MSG_TYPE
        );
        $msg->add_control_data($this->get_online_users_list());
        return $msg;
    }


    public function verify_access_token(string $senderID, string $message)
    {
        $this->online_users_list[$senderID]['auth'] = true;
        $user = json_decode($message, true);
        $this->online_users_list[$senderID]['user'] = $user;
        $token = $user['token'];
        $role = $user['role'];
        switch ($role) {
            case self::ANON_ROLE:
                break;
            case self::USER_ROLE:
                $this->online_users_list[$senderID]['abort'] = ( !$this->jwt->validate_token($token));
                break;

        }
    }

}