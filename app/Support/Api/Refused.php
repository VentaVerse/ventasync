<?php

namespace App\Support\Api;

/**
 * A business rule turned the request down. Every door shows the message:
 * the partner API with the status, an assistant as the tool's error.
 */
final class Refused extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
