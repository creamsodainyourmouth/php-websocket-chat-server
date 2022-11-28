<?php

namespace Src;

class PrivateMessage extends TextMessage
{
    public function serialize(): array
    {
        $msg = parent::serialize();
        $msg["receiver"] = $this->receiverID;
        return $msg;
    }
}