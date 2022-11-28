<?php

namespace Src;

class TextMessageFactory extends MessageFactory
{
    static function create_message(string $senderID, array $receiverID, string $body, int $type = Message::TEXT_MSG_TYPE): Message
    {
        return new TextMessage($senderID, $receiverID, $body);
    }
}