<?php

namespace Ace\Middlewares;

use Ace\Application;
use Ace\Middleware;

class AuthMiddleware extends Middleware
{
    /**
     * Run the authentication middleware check
     */
    protected function run(): void
    {
        if (Application::isGuest()) {
            Application::$app->session->setFlash('error', 'Please log in to view that page.');
            Application::$app->response->redirect('/login');
        }
    }
}
