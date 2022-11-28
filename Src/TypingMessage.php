<?php

namespace Src;

class TypingMessage extends TextMessage
{
    public function serialize(): array
    {
        $msg = parent::serialize();
        $msg['typing'] = true;
        return $msg;
    }
}