<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TagsController
{
    public function __construct(private Database $db, private Twig $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $pdo = $this->db->pdo();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();
        $stmt = $pdo->prepare('SELECT id, name, slug, created_at FROM tags ORDER BY name ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $pages = (int)ceil(($total ?: 0) / $perPage);
        return $this->view->render($response, 'admin/tags/index.twig', [
            'items' => $rows,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/tags/create.twig', [
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Nome obbligatorio'];
            return $response->withHeader('Location', '/admin/tags/create')->withStatus(302);
        }
        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }
        $stmt = $this->db->pdo()->prepare('INSERT INTO tags(name, slug) VALUES(:n, :s)');
        try {
            $stmt->execute([':n' => $name, ':s' => $slug]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tag creato'];
            return $response->withHeader('Location', '/admin/tags')->withStatus(302);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
            return $response->withHeader('Location', '/admin/tags/create')->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tags WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();
        if (!$item) {
            $response->getBody()->write('Tag non trovato');
            return $response->withStatus(404);
        }
        return $this->view->render($response, 'admin/tags/edit.twig', [
            'item' => $item,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Nome obbligatorio'];
            return $response->withHeader('Location', '/admin/tags/'.$id.'/edit')->withStatus(302);
        }
        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }
        $stmt = $this->db->pdo()->prepare('UPDATE tags SET name=:n, slug=:s WHERE id=:id');
        try {
            $stmt->execute([':n' => $name, ':s' => $slug, ':id' => $id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tag aggiornato'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', '/admin/tags')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('DELETE FROM tags WHERE id = :id');
        try {
            $stmt->execute([':id' => $id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Tag eliminato'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', '/admin/tags')->withStatus(302);
    }
}
