<?php

namespace Src;

class ControlMessageFactory extends MessageFactory
{
    static function create_message(
        string $senderID,
        array $receiverID,
        string $body,
        int $type = Message::TEXT_MSG_TYPE
    ): Message
    {

        return new ControlMessage($senderID, $receiverID, $body, $type);
    }
}