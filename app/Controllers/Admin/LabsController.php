<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class LabsController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $per = 10; $off = ($page-1)*$per; $pdo = $this->db->pdo();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM labs')->fetchColumn();
        $st = $pdo->prepare('SELECT id, name, city, country FROM labs ORDER BY name LIMIT :l OFFSET :o');
        $st->bindValue(':l', $per, \PDO::PARAM_INT);
        $st->bindValue(':o', $off, \PDO::PARAM_INT);
        $st->execute();
        return $this->view->render($response, 'admin/labs/index.twig', ['items'=>$st->fetchAll(), 'page'=>$page, 'pages'=>(int)ceil(max(0,$total)/$per)]);
    }

    public function create(Request $r, Response $res): Response
    {
        return $this->view->render($res, 'admin/labs/create.twig', ['csrf'=>$_SESSION['csrf']??'']);
    }

    public function store(Request $r, Response $res): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $res->withHeader('Location', $this->basePath . '/admin/labs/create')->withStatus(302);
        }

        $d = (array)$r->getParsedBody();
        $name = trim((string)($d['name'] ?? ''));
        $city = ($d['city'] ?? '') !== '' ? (string)$d['city'] : null;
        $country = ($d['country'] ?? '') !== '' ? (string)$d['country'] : null;

        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Name is required'];
            return $res->withHeader('Location', $this->basePath . '/admin/labs/create')->withStatus(302);
        }

        try {
            $this->db->pdo()->prepare('INSERT INTO labs(name, city, country) VALUES(?,?,?)')->execute([$name, $city, $country]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Lab created'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: '.$e->getMessage()];
            return $res->withHeader('Location', $this->basePath . '/admin/labs/create')->withStatus(302);
        }

        return $res->withHeader('Location', $this->basePath . '/admin/labs')->withStatus(302);
    }

    public function edit(Request $r, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $st = $this->db->pdo()->prepare('SELECT * FROM labs WHERE id=:id');
        $st->execute([':id' => $id]);
        $it = $st->fetch();
        if (!$it) {
            return $res->withStatus(404);
        }
        return $this->view->render($res, 'admin/labs/edit.twig', ['item' => $it, 'csrf' => $_SESSION['csrf'] ?? '']);
    }

    public function update(Request $r, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $res->withHeader('Location', $this->basePath . '/admin/labs/'.$id.'/edit')->withStatus(302);
        }

        $d = (array)$r->getParsedBody();
        $name = trim((string)($d['name'] ?? ''));
        $city = ($d['city'] ?? '') !== '' ? (string)$d['city'] : null;
        $country = ($d['country'] ?? '') !== '' ? (string)$d['country'] : null;

        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Name is required'];
            return $res->withHeader('Location', $this->basePath . '/admin/labs/'.$id.'/edit')->withStatus(302);
        }

        try {
            $this->db->pdo()->prepare('UPDATE labs SET name=?, city=?, country=? WHERE id=?')->execute([$name, $city, $country, $id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Lab updated'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: '.$e->getMessage()];
        }

        return $res->withHeader('Location', $this->basePath . '/admin/labs')->withStatus(302);
    }

    public function delete(Request $r, Response $res, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $res->withHeader('Location', $this->basePath . '/admin/labs')->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);
        try {
            $this->db->pdo()->prepare('DELETE FROM labs WHERE id=:id')->execute([':id' => $id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Lab deleted'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: '.$e->getMessage()];
        }

        return $res->withHeader('Location', $this->basePath . '/admin/labs')->withStatus(302);
    }
}
