<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 业务规则被违反时抛出，由 bootstrap/app.php 统一转成 JSON 错误。
 */
class DomainRuleException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
