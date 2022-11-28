<?php

namespace Src;
use Src\Message;

// TODO: Better use Builder pattern instead: message is composite object.
abstract class MessageFactory
{
    abstract static function create_message(
        string $senderID,
        array $receiverID,
        string $body,
        int $type = Message::TEXT_MSG_TYPE
    ): Message;
}