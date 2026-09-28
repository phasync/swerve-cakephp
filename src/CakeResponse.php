<?php

namespace Swerve\CakePHP;

use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Cake's Response from any PSR-7 response, for a controller action, which must return Cake's:
 *
 *     return CakeResponse::from(WebSocket::from($this->request, function (WebSocket $ws) {
 *         foreach ($ws as $message) {
 *             $ws->send("echo: $message");
 *         }
 *     }));
 *
 * Status, reason, headers and body are kept as they are, the body not copied: a 101's body is
 * the connection's way out.
 */
final class CakeResponse
{
    public static function from(ResponseInterface $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }
        $cake = (new Response(['stream' => $response->getBody()]))
            ->withStatus($response->getStatusCode(), $response->getReasonPhrase())
            ->withoutHeader('Content-Type');
        foreach ($response->getHeaders() as $name => $values) {
            $cake = $cake->withHeader($name, $values);
        }

        return $cake;
    }
}
