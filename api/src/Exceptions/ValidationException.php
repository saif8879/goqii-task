<?php

declare(strict_types=1);

namespace App\Exceptions;

class ValidationException extends HttpException
{
    /** @var array<string, string> */
    private $errors;

    /**
     * @param array<string, string> $errors Field name => first failing message.
     */
    public function __construct(array $errors, string $message = 'The submitted data is invalid.')
    {
        parent::__construct(422, $message);

        $this->errors = $errors;
    }

    /**
     * @return array<string, string> Field name => first failing message.
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'errors'  => $this->errors,
        ];
    }
}
