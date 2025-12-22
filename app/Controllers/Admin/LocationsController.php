<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Repositories\LocationRepository;
use App\Support\Hooks;
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
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/locations/create'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $desc = trim((string)($data['description'] ?? '')) ?: null;
        if ($name === '' || $slug === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Name and slug are required'];
            return $response->withHeader('Location', $this->redirect('/admin/locations/create'))->withStatus(302);
        }
        try {
            $id = $this->locations->create(['name' => $name, 'slug' => $slug, 'description' => $desc]);
            Hooks::doAction('metadata_location_created', $id, ['name' => $name, 'slug' => $slug]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Location created'];
            return $response->withHeader('Location', $this->redirect('/admin/locations'))->withStatus(302);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: ' . $e->getMessage()];
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

        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/locations/' . $id . '/edit'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $desc = trim((string)($data['description'] ?? '')) ?: null;
        if ($name === '' || $slug === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Name and slug are required'];
            return $response->withHeader('Location', $this->redirect('/admin/locations/' . $id . '/edit'))->withStatus(302);
        }
        try {
            $this->locations->update($id, ['name' => $name, 'slug' => $slug, 'description' => $desc]);
            Hooks::doAction('metadata_location_updated', $id, ['name' => $name, 'slug' => $slug]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Location updated'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', $this->redirect('/admin/locations'))->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/locations'))->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);
        try {
            $this->locations->delete($id);
            Hooks::doAction('metadata_location_deleted', $id);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Location deleted'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', $this->redirect('/admin/locations'))->withStatus(302);
    }
}
