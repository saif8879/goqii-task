<?php

declare(strict_types=1);

namespace App\Exceptions;

class UnauthorizedException extends HttpException
{
    /**
     * Machine-readable reason. The client uses this to tell "your access token
     * just aged out, go refresh" apart from "your credentials are wrong".
     *
     * @var string
     */
    private $reason;

    public function __construct(string $message, string $reason = 'unauthenticated')
    {
        parent::__construct(401, $message);

        $this->reason = $reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code'    => $this->reason,
        ];
    }
}
