<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TemplatesController
{
    public function __construct(private Database $db, private Twig $view)
    {
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

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/templates/create.twig', [
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        
        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Nome obbligatorio'];
            return $response->withHeader('Location', '/admin/templates/create')->withStatus(302);
        }
        
        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }

        // Process responsive columns
        $columns = [
            'desktop' => (int)($data['columns_desktop'] ?? $data['columns'] ?? 3),
            'tablet' => (int)($data['columns_tablet'] ?? 2),
            'mobile' => (int)($data['columns_mobile'] ?? 1)
        ];

        // Process settings from form data
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

        $libs = ['photoswipe'];
        if ($settings['masonry']) {
            $libs[] = 'masonry';
        }

        $stmt = $this->db->pdo()->prepare('INSERT INTO templates(name, slug, description, settings, libs) VALUES(:n, :s, :d, :settings, :libs)');
        try {
            $stmt->execute([
                ':n' => $name,
                ':s' => $slug,
                ':d' => $description,
                ':settings' => json_encode($settings),
                ':libs' => json_encode($libs)
            ]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Template creato'];
            return $response->withHeader('Location', '/admin/templates')->withStatus(302);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
            return $response->withHeader('Location', '/admin/templates/create')->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $template = $stmt->fetch();
        
        if (!$template) {
            $response->getBody()->write('Template non trovato');
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
        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        
        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Nome obbligatorio'];
            return $response->withHeader('Location', '/admin/templates/'.$id.'/edit')->withStatus(302);
        }
        
        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }

        // Process responsive columns
        $columns = [
            'desktop' => (int)($data['columns_desktop'] ?? $data['columns'] ?? 3),
            'tablet' => (int)($data['columns_tablet'] ?? 2),
            'mobile' => (int)($data['columns_mobile'] ?? 1)
        ];

        // Process settings from form data
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
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Template aggiornato'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', '/admin/templates')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('DELETE FROM templates WHERE id = :id');
        try {
            $stmt->execute([':id' => $id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Template eliminato'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
        }
        return $response->withHeader('Location', '/admin/templates')->withStatus(302);
    }
}