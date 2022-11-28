<?php

namespace Src;

class JointMessageFactory extends MessageFactory
{

    static function create_message(string $senderID, array $receiverID, string $body, int $type = Message::TEXT_MSG_TYPE): Message
    {
        return new JointMessage($senderID, $receiverID, $body, $type);
    }
}