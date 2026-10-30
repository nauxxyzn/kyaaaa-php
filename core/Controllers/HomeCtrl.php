<?php

declare(strict_types=1);

namespace Core\Controllers;

use Core\Models\HomeModel;

class HomeCtrl
{
    private HomeModel $homeModel;

    public function __construct()
    {
        $this->homeModel = new HomeModel();
    }

    public function index(): void
    {
        $data['title'] = 'Kyaaaa-PHP: a small PHP framework';
        $data['description'] = 'Kyaaaa-PHP is a small PHP 8.3 framework with a router, a safe query builder, sessions, CSRF protection and a mailer.';

        view('home', $data);
    }

    public function post(): void
    {
        // Reached only with a valid CSRF token (see Routes.php).
        redirectTo('/');
    }
}
