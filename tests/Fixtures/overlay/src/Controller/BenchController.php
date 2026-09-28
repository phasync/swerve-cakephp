<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Response;

/**
 * The benchmarked routes: JSON, and a page that uses the session. No authentication.
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
}
