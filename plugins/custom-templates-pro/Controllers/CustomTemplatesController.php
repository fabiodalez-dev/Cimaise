<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Controllers;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Services\SettingsService;
use CustomTemplatesPro\Services\TemplateUploadService;
use CustomTemplatesPro\Services\TemplateValidationService;
use CustomTemplatesPro\Services\GuidesGeneratorService;
use CustomTemplatesPro\Services\PluginTranslationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CustomTemplatesController extends BaseController
{
    private TemplateUploadService $uploadService;
    private GuidesGeneratorService $guidesService;
    private PluginTranslationService $translator;
    private string $language;

    public function __construct(
        private Database $db,
        private Twig $view
    ) {
        parent::__construct();

        // Get admin language setting (default to 'en' if db not available)
        $this->language = 'en';
        try {
            $settings = new SettingsService($db);
            $this->language = $settings->get('admin.language', 'en');
        } catch (\Throwable $e) {
            // Fallback to English if settings unavailable
        }

        // Initialize services with language
        $validator = new TemplateValidationService();
        $this->uploadService = new TemplateUploadService($db, $validator);

        $this->guidesService = new GuidesGeneratorService();
        $this->guidesService->setLanguage($this->language);

        $this->translator = new PluginTranslationService();
        $this->translator->setLanguage($this->language);

        // Register plugin translation Twig extension on this view instance
        $this->registerTwigExtension();
    }

    /**
     * Register plugin translation extension on the Twig instance
     */
    private function registerTwigExtension(): void
    {
        $env = $this->view->getEnvironment();

        // Check if extension is already registered to avoid duplicates
        foreach ($env->getExtensions() as $extension) {
            if ($extension instanceof \CustomTemplatesPro\Extensions\PluginTranslationTwigExtension) {
                return;
            }
        }

        // Register the extension
        require_once dirname(__DIR__) . '/Extensions/PluginTranslationTwigExtension.php';
        $env->addExtension(
            new \CustomTemplatesPro\Extensions\PluginTranslationTwigExtension($this->translator)
        );
    }

    /**
     * Get translation helper for templates
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator->get($key, $params);
    }

    /**
     * Get common template variables
     */
    private function getTemplateVars(array $extra = []): array
    {
        return array_merge([
            'csrf' => $_SESSION['csrf'] ?? '',
            'plugin_trans' => fn(string $key, array $params = []) => $this->trans($key, $params),
            'plugin_language' => $this->language,
        ], $extra);
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
            $recentTemplates = [...$recentTemplates, ...array_slice($templates, 0, 3)];
        }

        // Sort by installation date
        usort($recentTemplates, fn($a, $b) => strtotime($b['installed_at']) - strtotime($a['installed_at']));

        return $this->view->render($response, '@custom-templates-pro/admin/dashboard.twig', $this->getTemplateVars([
            'stats' => $stats,
            'recent_templates' => array_slice($recentTemplates, 0, 5),
            'guides_exist' => $this->guidesService->guidesExist(),
        ]));
    }

    /**
     * Template list page
     */
    public function list(Request $request, Response $response): Response
    {
        $type = $request->getQueryParams()['type'] ?? 'gallery';

        if (!in_array($type, ['gallery', 'album_page', 'homepage'])) {
            $type = 'gallery';
        }

        $templates = $this->uploadService->getTemplatesByType($type);

        return $this->view->render($response, '@custom-templates-pro/admin/list.twig', $this->getTemplateVars([
            'templates' => $templates,
            'current_type' => $type,
        ]));
    }

    /**
     * Upload template form
     */
    public function uploadForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, '@custom-templates-pro/admin/upload.twig', $this->getTemplateVars());
    }

    /**
     * Process template upload
     */
    public function upload(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => $this->trans('ctp.flash.csrf_invalid')
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $type = (string)($data['template_type'] ?? 'gallery');

        if (!in_array($type, ['gallery', 'album_page', 'homepage'])) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => $this->trans('ctp.flash.type_invalid')
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/upload'))->withStatus(302);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $zipFile = $uploadedFiles['template_zip'] ?? null;

        if (!$zipFile || $zipFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => $this->trans('ctp.flash.upload_error')
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/upload'))->withStatus(302);
        }

        // Move uploaded file to temporary directory
        $tmpPath = sys_get_temp_dir() . '/' . uniqid('template_') . '.zip';
        $zipFile->moveTo($tmpPath);

        // Process upload
        $result = $this->uploadService->processUpload([
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'name' => $zipFile->getClientFilename()
        ], $type);

        // Remove temporary file
        if (file_exists($tmpPath)) {
            unlink($tmpPath);
        }

        if ($result['success']) {
            $_SESSION['flash'][] = [
                'type' => 'success',
                'message' => $this->trans('ctp.flash.upload_success', ['name' => $result['metadata']['name']])
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
     * Toggle template active status
     */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => $this->trans('ctp.flash.csrf_invalid')
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);

        if ($this->uploadService->toggleTemplate($id)) {
            $_SESSION['flash'][] = [
                'type' => 'success',
                'message' => $this->trans('ctp.flash.toggle_success')
            ];
        } else {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => $this->trans('ctp.flash.toggle_error')
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
    }

    /**
     * Delete template
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => $this->trans('ctp.flash.csrf_invalid')
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);

        if ($this->uploadService->deleteTemplate($id)) {
            $_SESSION['flash'][] = [
                'type' => 'success',
                'message' => $this->trans('ctp.flash.delete_success')
            ];
        } else {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => $this->trans('ctp.flash.delete_error')
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
    }

    /**
     * Guides page
     */
    public function guides(Request $request, Response $response): Response
    {
        return $this->view->render($response, '@custom-templates-pro/admin/guides.twig', $this->getTemplateVars([
            'guides_language' => $this->guidesService->getLanguage(),
        ]));
    }

    /**
     * Download guide
     */
    public function downloadGuide(Request $request, Response $response, array $args): Response
    {
        $type = $args['type'] ?? 'gallery';

        if (!in_array($type, ['gallery', 'album_page', 'homepage'])) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => $this->trans('ctp.flash.guide_type_invalid')
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/guides'))->withStatus(302);
        }

        try {
            $guidePath = $this->guidesService->getGuidePath($type);
            $content = file_get_contents($guidePath);
            $filename = $this->guidesService->getDownloadFilename($type);

            $response->getBody()->write($content);

            return $response
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string)strlen($content));

        } catch (\Exception $e) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => $this->trans('ctp.flash.guide_download_error', ['error' => $e->getMessage()])
            ];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates/guides'))->withStatus(302);
        }
    }
}
