<?php

declare(strict_types=1);

namespace App\Exceptions;

class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'You are not allowed to perform this action.')
    {
        parent::__construct(403, $message);
    }
}
