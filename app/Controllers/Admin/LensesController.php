<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use App\Support\Hooks;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class LensesController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $per = 10; $off = ($page-1)*$per; $pdo = $this->db->pdo();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM lenses')->fetchColumn();
        $st = $pdo->prepare('SELECT id, brand, model, focal_min, focal_max, aperture_min FROM lenses ORDER BY brand, model LIMIT :l OFFSET :o');
        $st->bindValue(':l', $per, \PDO::PARAM_INT);
        $st->bindValue(':o', $off, \PDO::PARAM_INT);
        $st->execute();
        return $this->view->render($response, 'admin/lenses/index.twig', ['items'=>$st->fetchAll(), 'page'=>$page, 'pages'=>(int)ceil(max(0,$total)/$per)]);
    }

    public function create(Request $r, Response $res): Response
    {
        return $this->view->render($res, 'admin/lenses/create.twig', ['csrf'=>$_SESSION['csrf']??'']);
    }

    public function store(Request $r, Response $res): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $res->withHeader('Location', $this->basePath . '/admin/lenses/create')->withStatus(302);
        }

        $d = (array)$r->getParsedBody();
        $brand = trim((string)($d['brand'] ?? ''));
        $model = trim((string)($d['model'] ?? ''));
        $fmin = ($d['focal_min'] ?? '') !== '' ? (float)$d['focal_min'] : null;
        $fmax = ($d['focal_max'] ?? '') !== '' ? (float)$d['focal_max'] : null;
        $amin = ($d['aperture_min'] ?? '') !== '' ? (float)$d['aperture_min'] : null;

        if ($brand === '' || $model === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.brand_model_required')];
            return $res->withHeader('Location', $this->basePath . '/admin/lenses/create')->withStatus(302);
        }

        try {
            $pdo = $this->db->pdo();
            $pdo->prepare('INSERT INTO lenses(brand, model, focal_min, focal_max, aperture_min) VALUES(?,?,?,?,?)')->execute([$brand, $model, $fmin, $fmax, $amin]);
            $id = (int)$pdo->lastInsertId();
            Hooks::doAction('metadata_lens_created', $id, ['brand' => $brand, 'model' => $model]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.lens_created')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
            return $res->withHeader('Location', $this->basePath . '/admin/lenses/create')->withStatus(302);
        }

        return $res->withHeader('Location', $this->basePath . '/admin/lenses')->withStatus(302);
    }

    public function edit(Request $r, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $st = $this->db->pdo()->prepare('SELECT * FROM lenses WHERE id=:id');
        $st->execute([':id' => $id]);
        $it = $st->fetch();
        if (!$it) {
            return $res->withStatus(404);
        }
        return $this->view->render($res, 'admin/lenses/edit.twig', ['item' => $it, 'csrf' => $_SESSION['csrf'] ?? '']);
    }

    public function update(Request $r, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $res->withHeader('Location', $this->basePath . '/admin/lenses/'.$id.'/edit')->withStatus(302);
        }

        $d = (array)$r->getParsedBody();
        $brand = trim((string)($d['brand'] ?? ''));
        $model = trim((string)($d['model'] ?? ''));
        $fmin = ($d['focal_min'] ?? '') !== '' ? (float)$d['focal_min'] : null;
        $fmax = ($d['focal_max'] ?? '') !== '' ? (float)$d['focal_max'] : null;
        $amin = ($d['aperture_min'] ?? '') !== '' ? (float)$d['aperture_min'] : null;

        if ($brand === '' || $model === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.brand_model_required')];
            return $res->withHeader('Location', $this->basePath . '/admin/lenses/'.$id.'/edit')->withStatus(302);
        }

        try {
            $this->db->pdo()->prepare('UPDATE lenses SET brand=?, model=?, focal_min=?, focal_max=?, aperture_min=? WHERE id=?')->execute([$brand, $model, $fmin, $fmax, $amin, $id]);
            Hooks::doAction('metadata_lens_updated', $id, ['brand' => $brand, 'model' => $model]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.lens_updated')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
        }

        return $res->withHeader('Location', $this->basePath . '/admin/lenses')->withStatus(302);
    }

    public function delete(Request $r, Response $res, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $res->withHeader('Location', $this->basePath . '/admin/lenses')->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);
        try {
            $this->db->pdo()->prepare('DELETE FROM lenses WHERE id=:id')->execute([':id' => $id]);
            Hooks::doAction('metadata_lens_deleted', $id);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.lens_deleted')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
        }

        return $res->withHeader('Location', $this->basePath . '/admin/lenses')->withStatus(302);
    }
}
