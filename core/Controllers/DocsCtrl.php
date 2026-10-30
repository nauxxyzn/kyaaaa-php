<?php

declare(strict_types=1);

namespace Core\Controllers;

class DocsCtrl
{
    public function index(): void
    {
        $data['title'] = 'Documentation - Kyaaaa-PHP';
        $data['description'] = 'Learn routing, controllers, views, the query builder, sessions, CSRF protection, uploads and email in Kyaaaa-PHP.';

        view('docs', $data);
    }
}
