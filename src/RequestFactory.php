<?php

namespace Swerve\CakePHP;

use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Cake\Http\UriFactory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Cake's ServerRequest from swerve's PSR-7 request, as ServerRequestFactory::fromGlobals()
 * builds it from PHP's superglobals: Cake reads headers from a $_SERVER-style environment. The
 * body stays swerve's stream, and uploaded files stay swerve's.
 *
 * @internal
 */
final class RequestFactory extends ServerRequestFactory
{
    public static function fromPsr(ServerRequestInterface $request, Session $session): ServerRequest
    {
        $server = $request->getServerParams() + ['QUERY_STRING' => $request->getUri()->getQuery()];
        foreach ($request->getHeaders() as $name => $values) {
            $key = \strtoupper(\strtr($name, '-', '_'));
            if ('CONTENT_TYPE' !== $key && 'CONTENT_LENGTH' !== $key) {
                $key = "HTTP_$key";
            }
            $server[$key] = \implode(', ', $values);
        }
        ['uri' => $uri, 'base' => $base, 'webroot' => $webroot] = UriFactory::marshalUriAndBaseFromSapi($server);

        $cake = (new ServerRequest([
            'environment' => $server,
            'uri'         => $uri,
            'cookies'     => $request->getCookieParams(),
            'query'       => $request->getQueryParams(),
            'webroot'     => $webroot,
            'base'        => $base,
            'session'     => $session,
            'input'       => '',
        ]))->withBody($request->getBody());
        $cake = static::marshalBodyAndRequestMethod((array) $request->getParsedBody(), $cake);
        $cake = $cake->withUri($cake->getUri()->withScheme($cake->scheme()), true);

        return static::marshalFiles($request->getUploadedFiles(), $cake);
    }
}
