<?php

declare(strict_types=1);

namespace App\Domain\Agents\Exceptions;

use RuntimeException;

final class ToolSecurityException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 403,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
