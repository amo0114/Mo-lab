<?php

namespace Plugin\FakaBridge\Services;

class ProtocolV2Exception extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus)
    {
        parent::__construct($errorCode);
    }
}
