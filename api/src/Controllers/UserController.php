<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;

class UserController
{
    /** @var UserRepository */
    private $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

    /**
     * Admin-only: the route is registered with a role requirement. Regular
     * users have no reason to enumerate accounts.
     */
    public function index(Request $request): void
    {
        Response::json(['data' => $this->users->all()]);
    }
}
