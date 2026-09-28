<?php

/*
 * The routes of the swerve-cakephp test suite and benchmarks, connected by config/routes.php.
 * The named route is there on purpose: a second request connecting it again would throw.
 */

use App\SwerveTest\AuthProvider;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Routing\RouteBuilder;

return function (RouteBuilder $routes): void {
    $routes->connect('/bench/{action}', ['controller' => 'Bench']);
    $routes->registerMiddleware('auth', new AuthenticationMiddleware(new AuthProvider()));
    $routes->scope('/swerve-test', function (RouteBuilder $builder): void {
        $builder->applyMiddleware('auth');
        $builder->connect('/isolation/{v}', 'SwerveTest::isolation', ['_name' => 'isolation', 'pass' => ['v']]);
        $builder->connect('/{action}', ['controller' => 'SwerveTest']);
    });
};
