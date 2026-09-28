#!/bin/sh
# Create the CakePHP test application in tests/Fixtures/app, for CakePHP version $1 (5.2, 5.4, ...):
# the framework's own skeleton, with this package installed from the checkout (through
# Fixtures/package, which links only its composer.json and src/) and the test
# routes, controller and swerve.php of tests/Fixtures/overlay added. Idempotent.
set -eu
cd "$(dirname "$0")/Fixtures"
if [ ! -d app ]; then
    composer create-project --no-interaction --no-progress --prefer-dist "cakephp/app:$1.*" app
fi
cd app
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.swerve-cakephp '{"type": "path", "url": "../package", "options": {"symlink": true}}'
composer require --no-interaction --no-progress phasync/swerve-cakephp:@dev cakephp/authentication:^3.0
cp -R ../overlay/. .
grep -q swerve_routes config/routes.php || sed -i 's|^\(\s*\)\$routes->setRouteClass(DashedRoute::class);|&\n\1(require __DIR__ . "/swerve_routes.php")($routes);|' config/routes.php
grep -q swerve_routes config/routes.php
