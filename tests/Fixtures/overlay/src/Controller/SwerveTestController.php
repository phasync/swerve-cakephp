<?php
declare(strict_types=1);

namespace App\Controller;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Http\CallbackStream;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use phasync;
use phasync\Psr\UnbufferedStream;
use Swerve\Http\WebSocket;

/**
 * The actions the swerve-cakephp test suite calls, at /swerve-test/<action>.
 */
class SwerveTestController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication', ['requireIdentity' => false]);
    }

    public function json(): Response
    {
        return $this->send(['hello' => 'world']);
    }

    /** GET: a form with the CSRF token; POST: the name it sent. */
    public function form(): Response
    {
        if ($this->request->is('post')) {
            return $this->response->withStringBody('Hello ' . $this->request->getData('name'));
        }
        $token = $this->request->getAttribute('csrfToken');

        return $this->response->withStringBody("<form method=\"post\"><input type=\"hidden\" name=\"_csrfToken\" value=\"$token\"><input name=\"name\"></form>");
    }

    public function echo(): Response
    {
        return $this->send(['data' => $this->request->getData(), 'method' => $this->request->getMethod()]);
    }

    public function upload(): Response
    {
        $file = $this->request->getData('file');

        return $this->send([
            'name' => $file->getClientFilename(),
            'size' => $file->getSize(),
            'md5' => md5((string)$file->getStream()),
            'title' => $this->request->getData('title'),
        ]);
    }

    /**
     * Stores $v in every request-scoped place, waits so that other requests run, and reads them
     * back. $service comes from the application's container.
     */
    public function isolation(string $v, ServerRequest $service): Response
    {
        $this->request = $this->request->withAttribute('v', $v);
        $this->request->getSession()->write('v', $v);
        $this->Authentication->setIdentity(new ArrayObject(['username' => "user-$v"]));
        Configure::write('SwerveTest.v', $v);
        $this->wait((float)$this->request->getQuery('wait', 0.1));

        return $this->send([
            'attribute' => $this->request->getAttribute('v'),
            'route' => Router::getRequest()->getParam('v'),
            'url' => Router::url(['_name' => 'isolation', 'v' => $v]),
            'fullUrl' => Router::url('/', true),
            'service' => $service->getParam('v'),
            'session' => $this->request->getSession()->read('v'),
            'identity' => $this->Authentication->getIdentity()?->get('username'),
            'configure' => Configure::read('SwerveTest.v'),
        ]);
    }

    public function counter(): Response
    {
        $session = $this->request->getSession();
        $session->write('count', $session->read('count', 0) + 1);

        return $this->send(['count' => $session->read('count'), 'pid' => getmypid()]);
    }

    /** What the session holds, without starting one. */
    public function session(): Response
    {
        return $this->send(['session' => $this->request->getSession()->read(), 'id' => session_id()]);
    }

    public function flash(): ?Response
    {
        $this->Flash->success('Flashed once');

        return $this->redirect('/swerve-test/page');
    }

    public function page(): void
    {
    }

    public function login(): Response
    {
        $result = $this->Authentication->getResult();

        return $this->send(['ok' => $result?->isValid(), 'user' => $this->Authentication->getIdentity()?->get('username')]);
    }

    public function whoami(): Response
    {
        return $this->send(['user' => $this->Authentication->getIdentity()?->get('username')]);
    }

    public function logout(): Response
    {
        $this->Authentication->logout();

        return $this->send(['user' => null]);
    }

    /** Output outside any buffer, which makes headers_sent() true for the rest of the worker's life. */
    public function stray(): Response
    {
        echo "stray output\n";

        return $this->send(['headers_sent' => headers_sent()]);
    }

    /** Cake's own way to stream: a CallbackStream whose callback echoes. */
    public function stream(): Response
    {
        return $this->response->withType('text/plain')->withBody(new CallbackStream(function () {
            echo "first\n";
            $this->wait(0.5);
            echo "last\n";
        }));
    }

    /** Server-Sent Events from a coroutine, which holds no worker. */
    public function events(): Response
    {
        $stream = new UnbufferedStream(1, 10);
        phasync::go(function () use ($stream) {
            for ($i = 1; $i <= 3; $i++) {
                $stream->append("data: event $i\n\n");
                phasync::sleep(0.2);
            }
            $stream->end();
        });

        return $this->response->withType('text/event-stream')->withBody($stream);
    }

    /** A controller must return Cake's Response: the 101 of WebSocket::from() becomes one. */
    public function ws(): Response
    {
        $upgrade = WebSocket::from($this->request, function (WebSocket $ws) {
            foreach ($ws as $message) {
                $ws->send("echo: $message");
            }
        });
        $response = (new Response(['status' => $upgrade->getStatusCode(), 'stream' => $upgrade->getBody()]))->withoutHeader('Content-Type');
        foreach ($upgrade->getHeaders() as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }

    public function slow(): Response
    {
        $this->wait((float)$this->request->getQuery('t', 2));

        return $this->response->withStringBody('slow done');
    }

    public function memory(): Response
    {
        gc_collect_cycles();

        // PHP's cycle collector keeps a buffer of possible roots that grows but never shrinks: not a leak
        return $this->send(['memory' => memory_get_usage() - 8 * gc_status()['buffer_size'], 'pid' => getmypid()]);
    }

    /** Waits as an application would: sleep() blocks, but with phasync-ext it lets other requests run. */
    private function wait(float $seconds): void
    {
        extension_loaded('phasync') ? usleep((int)($seconds * 1e6)) : phasync::sleep($seconds);
    }

    private function send(mixed $data): Response
    {
        return $this->response->withType('application/json')->withStringBody(json_encode($data));
    }
}
