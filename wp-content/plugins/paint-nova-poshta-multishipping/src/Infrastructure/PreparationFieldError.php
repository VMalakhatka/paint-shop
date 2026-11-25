<?php
namespace Paint\NovaPoshta\Infrastructure;

defined('ABSPATH') || exit;

final class PreparationFieldError extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
