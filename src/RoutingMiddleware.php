<?php

namespace Swerve\CakePHP;

use Cake\Routing\Middleware\RoutingMiddleware as CakeRoutingMiddleware;

/**
 * Cake's RoutingMiddleware without its loadRoutes(), which connects the application's routes
 * for every request: Handler connects them once per worker. Each connection would also cost
 * memory for good, since PHP keeps about 256 bytes for each closure of an included file
 * (config/routes.php) every time the file is included.
 *
 * @internal Handler puts it in place of Cake's in each request's middleware queue
 */
final class RoutingMiddleware extends CakeRoutingMiddleware
{
    public static function from(CakeRoutingMiddleware $middleware): self
    {
        return new self($middleware->app);
    }

    protected function loadRoutes(): void
    {
    }
}
