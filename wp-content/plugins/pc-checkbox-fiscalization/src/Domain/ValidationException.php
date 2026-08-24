<?php

namespace Paint\CheckboxFiscalization\Domain;

use InvalidArgumentException;

defined('ABSPATH') || exit;

final class ValidationException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $fieldPath,
        string $message
    ) {
        parent::__construct($message);
    }
}

