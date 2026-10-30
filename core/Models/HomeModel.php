<?php

declare(strict_types=1);

namespace Core\Models;

use Core\Conf\Kyaaaa\DB;

class HomeModel
{
    private DB $users;

    public function __construct()
    {
        // The builder connects to the database lazily, on the first query.
        $this->users = DB::query('users');
    }

    /**
     * @return array<int, mixed>
     */
    public function get_users_active(): array
    {
        return $this->users->select('*')->get();
    }
}
