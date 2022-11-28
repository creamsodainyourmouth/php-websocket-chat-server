<?php

namespace Src;
use Src\IMessage;
use Src\Message;

class ControlMessage extends Message implements IMessage
{
    private array $control_data = [];

    public function serialize(): array
    {
        $msg = parent::serialize();;
        $msg["control_data"] = $this->control_data;
        return $msg;
    }

    public function add_control_data(array $data)
    {
        $this->control_data = $data;
    }

    public function get_receivers(): array
    {
        return $this->receiverID;
    }

}