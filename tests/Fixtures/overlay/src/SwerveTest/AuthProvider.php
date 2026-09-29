<?php
declare(strict_types=1);

namespace App\SwerveTest;

use ArrayObject;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use phasync;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Session and form login for the test routes: any username, with the password "secret". With
 * ?prewait=, it waits that many seconds first, as a middleware that queries a database would.
 */
class AuthProvider implements AuthenticationServiceProviderInterface
{
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $wait = (float)($request->getQueryParams()['prewait'] ?? 0);
        if ($wait > 0) {
            extension_loaded('phasync') ? usleep((int)($wait * 1e6)) : phasync::sleep($wait);
        }
        $identifier = ['Authentication.Callback' => [
            'callback' => fn (array $data) => ($data['password'] ?? null) === 'secret'
                ? new ArrayObject(['username' => $data['username'] ?? ''])
                : null,
        ]];
        $service = new AuthenticationService();
        $service->loadAuthenticator('Authentication.Session', ['identifier' => $identifier]);
        $service->loadAuthenticator('Authentication.Form', ['identifier' => $identifier, 'loginUrl' => '/swerve-test/login']);

        return $service;
    }
}
