<?php

namespace Src;
interface IMessage
{
    // Returns JSON string representation of the message.

    function represent(): string;

    function get_receivers(): array;

    function serialize(): array;

}