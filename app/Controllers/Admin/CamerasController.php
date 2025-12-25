<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use App\Support\Hooks;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CamerasController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $per = 10; $off = ($page-1)*$per; $pdo = $this->db->pdo();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM cameras')->fetchColumn();
        $stmt = $pdo->prepare('SELECT id, make, model FROM cameras ORDER BY make, model LIMIT :l OFFSET :o');
        $stmt->bindValue(':l', $per, \PDO::PARAM_INT); $stmt->bindValue(':o', $off, \PDO::PARAM_INT); $stmt->execute();
        return $this->view->render($response, 'admin/cameras/index.twig', [
            'items' => $stmt->fetchAll(), 'page'=>$page, 'pages'=>(int)ceil(max(0,$total)/$per)
        ]);
    }

    public function create(Request $request, Response $response): Response
    { return $this->view->render($response, 'admin/cameras/create.twig', ['csrf'=>$_SESSION['csrf']??'']); }

    public function store(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->basePath . '/admin/cameras/create')->withStatus(302);
        }

        $d = (array)$request->getParsedBody();
        $make = trim((string)($d['make'] ?? ''));
        $model = trim((string)($d['model'] ?? ''));

        if ($make === '' || $model === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.make_model_required')];
            return $response->withHeader('Location', $this->basePath . '/admin/cameras/create')->withStatus(302);
        }

        try {
            $pdo = $this->db->pdo();
            $pdo->prepare('INSERT INTO cameras(make, model) VALUES(:a,:b)')->execute([':a'=>$make, ':b'=>$model]);
            $id = (int)$pdo->lastInsertId();
            Hooks::doAction('metadata_camera_created', $id, ['make' => $make, 'model' => $model]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.camera_created')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
            return $response->withHeader('Location', $this->basePath . '/admin/cameras/create')->withStatus(302);
        }

        return $response->withHeader('Location', $this->basePath . '/admin/cameras')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $st = $this->db->pdo()->prepare('SELECT * FROM cameras WHERE id=:id');
        $st->execute([':id' => $id]);
        $it = $st->fetch();
        if (!$it) {
            return $response->withStatus(404);
        }
        return $this->view->render($response, 'admin/cameras/edit.twig', ['item' => $it, 'csrf' => $_SESSION['csrf'] ?? '']);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->basePath . '/admin/cameras/'.$id.'/edit')->withStatus(302);
        }

        $d = (array)$request->getParsedBody();
        $make = trim((string)($d['make'] ?? ''));
        $model = trim((string)($d['model'] ?? ''));

        if ($make === '' || $model === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.make_model_required')];
            return $response->withHeader('Location', $this->basePath . '/admin/cameras/'.$id.'/edit')->withStatus(302);
        }

        try {
            $this->db->pdo()->prepare('UPDATE cameras SET make=:a, model=:b WHERE id=:id')->execute([':a'=>$make, ':b'=>$model, ':id'=>$id]);
            Hooks::doAction('metadata_camera_updated', $id, ['make' => $make, 'model' => $model]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.camera_updated')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
        }

        return $response->withHeader('Location', $this->basePath . '/admin/cameras')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->basePath . '/admin/cameras')->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);
        try {
            $this->db->pdo()->prepare('DELETE FROM cameras WHERE id=:id')->execute([':id' => $id]);
            Hooks::doAction('metadata_camera_deleted', $id);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.camera_deleted')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
        }

        return $response->withHeader('Location', $this->basePath . '/admin/cameras')->withStatus(302);
    }
}
