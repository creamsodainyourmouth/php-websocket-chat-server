<?php

namespace Src;
use Src\IMessage;
use Src\Message;

class TextMessage extends Message
{

    function get_receivers(): array
    {
        $receivers = array_flip($this->receiverID);
        return array_flip($receivers);
    }
}