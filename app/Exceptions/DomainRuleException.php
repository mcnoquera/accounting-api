<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business rule was violated (e.g. posting an unbalanced entry). Rendered as HTTP 422.
 */
class DomainRuleException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
