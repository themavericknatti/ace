<?php

namespace Ace\Exceptions;

use Exception;
use Ace\Validator;

/**
 * Thrown when request validation fails.
 */
class ValidationException extends Exception
{
    protected ?Validator $validator = null;
    protected array $errors = [];

    public function __construct(string $message = "The given data was invalid.", int $code = 422, ?Validator $validator = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->validator = $validator;
        $this->errors = $validator ? $validator->errors() : [];
    }

    /**
     * Get the validation error messages.
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get the validator instance.
     */
    public function getValidator(): ?Validator
    {
        return $this->validator;
    }
}
