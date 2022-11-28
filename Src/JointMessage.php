<?php

namespace Src;
use PharIo\Manifest\ElementCollection;
use Src\Chat;

class JointMessage extends TextMessage implements IMessage
{
    private $user_data = null;

    public function get_receivers(): array
    {
        $receivers = array_flip($this->receiverID);
        unset($receivers[$this->senderID]);
        return array_flip($receivers);
    }

    public function serialize(): array
    {
        $msg = parent::serialize();
        $msg["receiver"] = Chat::JOINT_CHAT_ID;

        if ($this->user_data !== null) {
            $msg['user'] = $this->user_data;
        }
        return $msg;
    }

    public function add_user_data(array $user_data)
    {
        $this->user_data = $user_data;
    }

}