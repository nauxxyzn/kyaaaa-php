<?php

declare(strict_types=1);

namespace Core\Conf;

use Core\Conf\Kyaaaa\HttpException;
use Core\Conf\Kyaaaa\Router;

class Routes
{
    public function kyaaaaRun(): void
    {
        $router = new Router();

        $router->get('/', [\Core\Controllers\HomeCtrl::class, 'index']);
        $router->get('/docs', [\Core\Controllers\DocsCtrl::class, 'index']);

        // CSRF example: ->csrf() rejects the request (419) unless it carries a valid
        // token. In a form echo csrf()->field(), for AJAX send the token in the
        // X-CSRF-Token header (see csrf()->metaTag()).
        $router->post('/post', [\Core\Controllers\HomeCtrl::class, 'post'])->csrf();

        // Middleware example (START)
        $router->get('/items/:id', function ($id) {
            echo esc(session()->getFlash('notif'));
        })->middleware(function ($id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new HttpException(400, 'Item id must be an integer');
            }
        });
        // Middleware example (END)

        $router->run();
    }
}
