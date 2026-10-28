<?php

namespace Ace\Middlewares;

use Ace\Middleware;
use Ace\Firewall;

class FirewallMiddleware extends Middleware
{
    protected function run(): void
    {
        (new Firewall())->inspect();
    }
}
