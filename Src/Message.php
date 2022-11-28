<?php

namespace Src;
use Src\IMessage;

abstract class Message implements IMessage
{
    const GREET_MSG_TYPE = 0;
    const FAREWELL_MSG_TYPE = 1;
    const TEXT_MSG_TYPE = 2;
    const ONLINE_LIST_MSG_TYPE = 3;
    const JOINT_MSG_TYPE = 5;
    const PRIVATE_MSG_TYPE = 6;

    protected string $type;
    protected string $senderID;
    protected array $receiverID;
    protected string $body;

    public function serialize(): array
    {
        $msg = [];
        $msg["sender"] = $this->senderID;
        $msg["receiver"] = $this->receiverID;
        $msg["body"] = $this->body;
        $msg["type"] = $this->type;
        $msg["typing"] = false;
        return $msg;
    }


    public function __construct(string $senderID, array $receiverID, string $body, int $type=self::TEXT_MSG_TYPE)
    {
        $this->senderID = $senderID;
        $this->receiverID = $receiverID;
        $this->body = $body;
        $this->type = $type;
    }

    public function represent(): string
    {
        return json_encode(static::serialize(), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    }
}
