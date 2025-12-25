<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TemplatesController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $stmt = $this->db->pdo()->query('SELECT * FROM templates ORDER BY name ASC');
        $templates = $stmt->fetchAll() ?: [];
        
        // Decode JSON fields for display
        foreach ($templates as &$template) {
            $template['settings'] = json_decode($template['settings'] ?? '{}', true) ?: [];
            $template['libs'] = json_decode($template['libs'] ?? '[]', true) ?: [];
        }
        
        return $this->view->render($response, 'admin/templates/index.twig', [
            'templates' => $templates,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    // New template creation is disabled
    public function create(Request $request, Response $response): Response
    {
        $_SESSION['flash'][] = ['type' => 'warning', 'message' => trans('admin.flash.templates_disabled')];
        return $response->withHeader('Location', $this->redirect('/admin/templates'))->withStatus(302);
    }

    // Saving new templates is disabled
    public function store(Request $request, Response $response): Response
    {
        $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.operation_not_allowed')];
        return $response->withHeader('Location', $this->redirect('/admin/templates'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $template = $stmt->fetch();
        
        if (!$template) {
            $response->getBody()->write(trans('admin.flash.template_not_found'));
            return $response->withStatus(404);
        }
        
        // Decode settings for form display
        $template['settings'] = json_decode($template['settings'] ?? '{}', true) ?: [];
        $template['libs'] = json_decode($template['libs'] ?? '[]', true) ?: [];
        
        return $this->view->render($response, 'admin/templates/edit.twig', [
            'item' => $template,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/templates/'.$id.'/edit'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));

        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.name_required')];
            return $response->withHeader('Location', $this->redirect('/admin/templates/'.$id.'/edit'))->withStatus(302);
        }
        
        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }

        // Process responsive columns - simplified structure
        $columns = [
            'desktop' => (int)($data['columns_desktop'] ?? 3),
            'tablet' => (int)($data['columns_tablet'] ?? 2),
            'mobile' => (int)($data['columns_mobile'] ?? 1)
        ];

        // Process settings from form data - simplified structure
        $settings = [
            'layout' => $data['layout'] ?? 'grid',
            'columns' => $columns,
            'masonry' => isset($data['masonry']),
            'photoswipe' => [
                'loop' => isset($data['photoswipe_loop']),
                'zoom' => isset($data['photoswipe_zoom']),
                'share' => isset($data['photoswipe_share']),
                'counter' => isset($data['photoswipe_counter']),
                'arrowKeys' => isset($data['photoswipe_arrowkeys']),
                'escKey' => isset($data['photoswipe_esckey']),
                'bgOpacity' => (float)($data['photoswipe_bg_opacity'] ?? 0.8),
                'spacing' => (float)($data['photoswipe_spacing'] ?? 0.12),
                'allowPanToNext' => isset($data['photoswipe_pan_to_next'])
            ]
        ];

        // Magazine-specific settings for Magazine Split template
        $slugStmt = $this->db->pdo()->prepare('SELECT slug FROM templates WHERE id = :id');
        $slugStmt->execute([':id' => $id]);
        $templateSlug = $slugStmt->fetchColumn();
        if ($templateSlug === 'magazine-split') {
            $magDur1 = (int)($data['mag_duration_1'] ?? 60);
            $magDur2 = (int)($data['mag_duration_2'] ?? 72);
            $magDur3 = (int)($data['mag_duration_3'] ?? 84);
            $magGap = (int)($data['mag_gap'] ?? 20);
            $settings['layout'] = $data['layout'] ?? 'magazine';
            $settings['masonry'] = true;
            $settings['magazine'] = [
                'durations' => [max(10,$magDur1), max(10,$magDur2), max(10,$magDur3)],
                'gap' => max(0, min(80, $magGap)),
            ];
        }

        // Masonry Full (masonry_fit) gap settings
        if (($settings['layout'] ?? '') === 'masonry_fit') {
            $gapH = (int)($data['masonry_gap_h'] ?? 16);
            $gapV = (int)($data['masonry_gap_v'] ?? 16);
            $settings['masonry'] = true;
            $settings['gap'] = [
                'horizontal' => max(0, min(100, $gapH)),
                'vertical' => max(0, min(100, $gapV)),
            ];
        }

        $libs = ['photoswipe'];
        if ($settings['masonry']) {
            $libs[] = 'masonry';
        }

        $stmt = $this->db->pdo()->prepare('UPDATE templates SET name=:n, slug=:s, description=:d, settings=:settings, libs=:libs WHERE id=:id');
        try {
            $stmt->execute([
                ':n' => $name,
                ':s' => $slug,
                ':d' => $description,
                ':settings' => json_encode($settings),
                ':libs' => json_encode($libs),
                ':id' => $id
            ]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.template_updated')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic')];
        }
        return $response->withHeader('Location', $this->redirect('/admin/templates'))->withStatus(302);
    }

    // Deleting templates is disabled
    public function delete(Request $request, Response $response, array $args): Response
    {
        $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.operation_not_allowed')];
        return $response->withHeader('Location', $this->redirect('/admin/templates'))->withStatus(302);
    }
}
