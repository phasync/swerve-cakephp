<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Response;

/**
 * The benchmarked routes: JSON, a page that uses the session, and a wait of ?ms= (default 10) in
 * usleep(), as a database query waits: it blocks the worker without phasync-ext. No authentication.
 */
class BenchController extends AppController
{
    public function json(): Response
    {
        return $this->response->withType('application/json')->withStringBody(json_encode(['hello' => 'world']));
    }

    public function session(): Response
    {
        $session = $this->request->getSession();
        $session->write('visits', $session->read('visits', 0) + 1);

        return $this->response->withType('application/json')->withStringBody(json_encode(['visits' => $session->read('visits')]));
    }

    public function usleep(): Response
    {
        $ms = (int)$this->request->getQuery('ms', 10);
        usleep(1000 * $ms);

        return $this->response->withType('application/json')->withStringBody(json_encode(['waited' => $ms]));
    }
}
