<?php

declare(strict_types=1);

namespace App;

/** An error the client may see: an HTTP status and a message that is safe to show. */
final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
