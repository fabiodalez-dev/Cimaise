<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;use App\Repositories\LocationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class LocationsController extends BaseController
{
    public function __construct(private LocationRepository $locations, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $items = $this->locations->getAll();
        return $this->view->render($response, 'admin/locations/index.twig', [
            'locations' => $items,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/locations/create.twig', [
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $desc = trim((string)($data['description'] ?? '')) ?: null;
        if ($name === '' || $slug === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Name e slug sono obbligatori'];
            return $response->withHeader('Location', $this->redirect('/admin/locations/create'))->withStatus(302);
        }
        try {
            $this->locations->create(['name' => $name, 'slug' => $slug, 'description' => $desc]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Location creata'];
            return $response->withHeader('Location', $this->redirect('/admin/locations'))->withStatus(302);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
            return $response->withHeader('Location', $this->redirect('/admin/locations/create'))->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $loc = $this->locations->getById($id);
        if (!$loc) { return $response->withStatus(404); }
        return $this->view->render($response, 'admin/locations/edit.twig', [
            'location' => $loc,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $desc = trim((string)($data['description'] ?? '')) ?: null;
        if ($name === '' || $slug === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Name e slug sono obbligatori'];
            return $response->withHeader('Location', $this->redirect('/admin/locations/' . $id . '/edit'))->withStatus(302);
        }
        try {
            $this->locations->update($id, ['name' => $name, 'slug' => $slug, 'description' => $desc]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Location aggiornata'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', $this->redirect('/admin/locations')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        try {
            $this->locations->delete($id);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Location eliminata'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', $this->redirect('/admin/locations')->withStatus(302);
    }
}
