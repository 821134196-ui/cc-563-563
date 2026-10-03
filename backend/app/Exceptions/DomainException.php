<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 业务规则冲突（如重复入库、预检证据锁定、重复赔付）。
 */
class DomainException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'domain_error',
        public readonly int $statusCode = 422,
    ) {
        parent::__construct($message);
    }
}
