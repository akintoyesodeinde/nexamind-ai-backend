<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class QuotaExceededException extends HttpException
{
    /**
     * @param  array<string, mixed>  $quotaDetails
     */
    public function __construct(
        string $message,
        public readonly array $quotaDetails = [],
    ) {
        parent::__construct(429, $message);
    }
}
