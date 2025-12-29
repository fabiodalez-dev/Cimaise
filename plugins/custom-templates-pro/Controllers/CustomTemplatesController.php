<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Controllers;

use App\Controllers\BaseController;
use App\Support\Database;
use CustomTemplatesPro\Services\TemplateUploadService;
use CustomTemplatesPro\Services\TemplateValidationService;
use CustomTemplatesPro\Services\GuidesGeneratorService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CustomTemplatesController extends BaseController
{
    private TemplateUploadService $uploadService;
    private GuidesGeneratorService $guidesService;

    public function __construct(
        private Database $db,
        private Twig $view
    ) {
        parent::__construct();

        $validator = new TemplateValidationService();
        $this->uploadService = new TemplateUploadService($db, $validator);
        $this->guidesService = new GuidesGeneratorService();
    }

    /**
     * Dashboard del plugin
     */
    public function dashboard(Request $request, Response $response): Response
    {
        $stats = $this->uploadService->getStats();

        $recentTemplates = [];
        foreach (['gallery', 'album_page', 'homepage'] as $type) {
            $templates = $this->uploadService->getTemplatesByType($type);
            $recentTemplates = array_merge($recentTemplates, array_slice($templates, 0, 3));
        }

        // Ordina per data installazione
        usort($recentTemplates, function ($a, $b) {
            return strtotime($b['installed_at']) - strtotime($a['installed_at']);
        });

        return $this->view->render($response, '@custom-templates-pro/admin/dashboard.twig', [
            'stats' => $stats,
            'recent_templates' => array_slice($recentTemplates, 0, 5),
            'guides_exist' => $this->guidesService->guidesExist(),
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    /**
     * Pagina lista templates
     */
    public function list(Request $request, Response $response): Response
    {
        $type = $request->getQueryParams()['type'] ?? 'gallery';

        if (!in_array($type, ['gallery', 'album_page', 'homepage'])) {
            $type = 'gallery';
        }

        $templates = $this->uploadService->getTemplatesByType($type);

        return $this->view->render($response, '@custom-templates-pro/admin/list.twig', [
            'templates' => $templates,
            'current_type' => $type,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    /**
     * Pagina upload template
     */
    public function uploadForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, '@custom-templates-pro/admin/upload.twig', [
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    /**
     * Processa upload template
     */
    public function upload(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => 'Token CSRF non valido'
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $type = (string)($data['template_type'] ?? 'gallery');

        if (!in_array($type, ['gallery', 'album_page', 'homepage'])) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => 'Tipo di template non valido'
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/upload'))->withStatus(302);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $zipFile = $uploadedFiles['template_zip'] ?? null;

        if (!$zipFile || $zipFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => 'Errore durante l\'upload del file'
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/upload'))->withStatus(302);
        }

        // Sposta file caricato in directory temporanea
        $tmpPath = sys_get_temp_dir() . '/' . uniqid('template_') . '.zip';
        $zipFile->moveTo($tmpPath);

        // Processa upload
        $result = $this->uploadService->processUpload([
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'name' => $zipFile->getClientFilename()
        ], $type);

        // Elimina file temporaneo
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }

        if ($result['success']) {
            $_SESSION['flash'][] = [
                'type' => 'success',
                'message' => "Template '{$result['metadata']['name']}' caricato con successo!"
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
        } else {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => $result['error']
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/upload'))->withStatus(302);
        }
    }

    /**
     * Attiva/disattiva template
     */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => 'Token CSRF non valido'
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);

        if ($this->uploadService->toggleTemplate($id)) {
            $_SESSION['flash'][] = [
                'type' => 'success',
                'message' => 'Stato template aggiornato'
            ];
        } else {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => 'Errore durante l\'aggiornamento del template'
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
    }

    /**
     * Elimina template
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => 'Token CSRF non valido'
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);

        if ($this->uploadService->deleteTemplate($id)) {
            $_SESSION['flash'][] = [
                'type' => 'success',
                'message' => 'Template eliminato con successo'
            ];
        } else {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => 'Errore durante l\'eliminazione del template'
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
    }

    /**
     * Pagina guide
     */
    public function guides(Request $request, Response $response): Response
    {
        // Genera guide se non esistono
        if (!$this->guidesService->guidesExist()) {
            $this->guidesService->generateAllGuides();
        }

        return $this->view->render($response, '@custom-templates-pro/admin/guides.twig', [
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    /**
     * Download guida
     */
    public function downloadGuide(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'] ?? 'gallery';

        if (!in_array($type, ['gallery', 'album_page', 'homepage'])) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => 'Tipo di guida non valido'
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/guides'))->withStatus(302);
        }

        try {
            $guidePath = $this->guidesService->getGuidePath($type);

            if (!file_exists($guidePath)) {
                // Genera guida se non esiste
                $this->guidesService->generateAllGuides();
            }

            $content = file_get_contents($guidePath);
            $filename = basename($guidePath);

            $response->getBody()->write($content);

            return $response
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string)strlen($content));

        } catch (\Exception $e) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => 'Errore durante il download della guida: ' . $e->getMessage()
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/guides'))->withStatus(302);
        }
    }
}
