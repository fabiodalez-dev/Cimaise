<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use App\Support\Hooks;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class FilmsController extends BaseController
{
    /**
     * Film type options
     */
    public const FILM_TYPES = [
        'bw_negative' => 'B&W Traditional',
        'bw_chromogenic' => 'B&W Chromogenic (C-41)',
        'bw_reversal' => 'B&W Reversal',
        'bw_infrared' => 'B&W Infrared',
        'bw' => 'B&W (Legacy)',
        'c41_color_negative' => 'C-41 Color Negative',
        'e6_slide' => 'E-6 Slide/Reversal',
        'ecn2_cinema' => 'ECN-2 Cinema',
        'color_negative' => 'Color Negative (Legacy)',
        'color_reversal' => 'Color Reversal (Legacy)',
        'instant_integral' => 'Instant/Integral',
        'digital' => 'Digital',
        'other' => 'Other',
    ];

    /**
     * Film format options
     */
    public const FILM_FORMATS = [
        '35mm' => '35mm',
        '120' => '120 (Medium Format)',
        '4x5' => '4x5 (Large Format)',
        '8x10' => '8x10 (Large Format)',
        'instant' => 'Instant',
        'instant_mini' => 'Instant Mini',
        'instant_square' => 'Instant Square',
        'instant_wide' => 'Instant Wide',
        'digital' => 'Digital',
        'other' => 'Other',
    ];

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $per = 10; $off = ($page-1)*$per; $pdo = $this->db->pdo();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM films')->fetchColumn();
        $st = $pdo->prepare('SELECT id, brand, name, iso, format, type FROM films ORDER BY brand, name LIMIT :l OFFSET :o');
        $st->bindValue(':l', $per, \PDO::PARAM_INT);
        $st->bindValue(':o', $off, \PDO::PARAM_INT);
        $st->execute();
        return $this->view->render($response, 'admin/films/index.twig', [
            'items' => $st->fetchAll(),
            'page' => $page,
            'pages' => (int)ceil(max(0,$total)/$per),
            'film_types' => self::FILM_TYPES,
            'film_formats' => self::FILM_FORMATS
        ]);
    }

    public function create(Request $r, Response $res): Response
    {
        return $this->view->render($res, 'admin/films/create.twig', [
            'csrf' => $_SESSION['csrf'] ?? '',
            'film_types' => self::FILM_TYPES,
            'film_formats' => self::FILM_FORMATS
        ]);
    }

    public function store(Request $r, Response $res): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $res->withHeader('Location', $this->basePath . '/admin/films/create')->withStatus(302);
        }

        $d = (array)$r->getParsedBody();
        $brand = trim((string)($d['brand'] ?? ''));
        $name = trim((string)($d['name'] ?? ''));
        $iso = ($d['iso'] ?? '') !== '' ? (int)$d['iso'] : null;
        $format = (string)($d['format'] ?? '35mm');
        $type = (string)($d['type'] ?? 'bw');

        if ($brand === '' || $name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.brand_name_required')];
            return $res->withHeader('Location', $this->basePath . '/admin/films/create')->withStatus(302);
        }

        try {
            $pdo = $this->db->pdo();
            $pdo->prepare('INSERT INTO films(brand, name, iso, format, type) VALUES(?,?,?,?,?)')->execute([$brand, $name, $iso, $format, $type]);
            $id = (int)$pdo->lastInsertId();
            Hooks::doAction('metadata_film_created', $id, ['brand' => $brand, 'name' => $name]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.film_created')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
            return $res->withHeader('Location', $this->basePath . '/admin/films/create')->withStatus(302);
        }

        return $res->withHeader('Location', $this->basePath . '/admin/films')->withStatus(302);
    }

    public function edit(Request $r, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $st = $this->db->pdo()->prepare('SELECT * FROM films WHERE id=:id');
        $st->execute([':id' => $id]);
        $it = $st->fetch();
        if (!$it) {
            return $res->withStatus(404);
        }
        return $this->view->render($res, 'admin/films/edit.twig', [
            'item' => $it,
            'csrf' => $_SESSION['csrf'] ?? '',
            'film_types' => self::FILM_TYPES,
            'film_formats' => self::FILM_FORMATS
        ]);
    }

    public function update(Request $r, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $res->withHeader('Location', $this->basePath . '/admin/films/'.$id.'/edit')->withStatus(302);
        }

        $d = (array)$r->getParsedBody();
        $brand = trim((string)($d['brand'] ?? ''));
        $name = trim((string)($d['name'] ?? ''));
        $iso = ($d['iso'] ?? '') !== '' ? (int)$d['iso'] : null;
        $format = (string)($d['format'] ?? '35mm');
        $type = (string)($d['type'] ?? 'bw');

        if ($brand === '' || $name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.brand_name_required')];
            return $res->withHeader('Location', $this->basePath . '/admin/films/'.$id.'/edit')->withStatus(302);
        }

        try {
            $this->db->pdo()->prepare('UPDATE films SET brand=?, name=?, iso=?, format=?, type=? WHERE id=?')->execute([$brand, $name, $iso, $format, $type, $id]);
            Hooks::doAction('metadata_film_updated', $id, ['brand' => $brand, 'name' => $name]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.film_updated')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
        }

        return $res->withHeader('Location', $this->basePath . '/admin/films')->withStatus(302);
    }

    public function delete(Request $r, Response $res, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($r)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $res->withHeader('Location', $this->basePath . '/admin/films')->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);
        try {
            $this->db->pdo()->prepare('DELETE FROM films WHERE id=:id')->execute([':id' => $id]);
            Hooks::doAction('metadata_film_deleted', $id);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.film_deleted')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic') . ': ' . $e->getMessage()];
        }

        return $res->withHeader('Location', $this->basePath . '/admin/films')->withStatus(302);
    }
}
