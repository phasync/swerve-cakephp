<?php
declare(strict_types=1);

namespace App\SwerveTest;

use ArrayObject;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Session and form login for the test routes: any username, with the password "secret".
 */
class AuthProvider implements AuthenticationServiceProviderInterface
{
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
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
