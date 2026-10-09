<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Business or HTTP error that is safe to show to the client. */
final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
