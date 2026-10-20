<?php

namespace Ace\Middlewares;

use Ace\Application;
use Ace\Middleware;

class GuestMiddleware extends Middleware
{
    /**
     * Run the guest middleware check
     */
    protected function run(): void
    {
        if (!Application::isGuest()) {
            Application::$app->response->redirect('/profile');
        }
    }
}
