<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Services\TypographyService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TypographyController extends BaseController
{
    private TypographyService $typographyService;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
        $this->typographyService = new TypographyService(new SettingsService($this->db));
    }

    /**
     * Show typography settings page
     */
    public function index(Request $request, Response $response): Response
    {
        $fonts = $this->typographyService->getAllFonts();
        $typography = $this->typographyService->getTypography();
        $contexts = TypographyService::CONTEXTS;

        // Group serif fonts by category
        $serifByCategory = [
            'editorial' => [],
            'display' => [],
            'modern' => [],
        ];
        foreach ($fonts['serif'] as $slug => $font) {
            $category = $font['category'] ?? 'editorial';
            $serifByCategory[$category][$slug] = $font;
        }

        // Group sans fonts by category
        $sansByCategory = [
            'clean' => [],
            'geometric' => [],
            'readable' => [],
        ];
        foreach ($fonts['sans'] as $slug => $font) {
            $category = $font['category'] ?? 'clean';
            $sansByCategory[$category][$slug] = $font;
        }

        return $this->view->render($response, 'admin/typography/index.twig', [
            'fonts' => $fonts,
            'serifByCategory' => $serifByCategory,
            'sansByCategory' => $sansByCategory,
            'typography' => $typography,
            'contexts' => $contexts,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    /**
     * Save typography settings
     */
    public function save(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/typography'))->withStatus(302);
        }

        $data = (array) $request->getParsedBody();

        try {
            $this->typographyService->saveTypography($data);

            // Regenerate CSS file
            $cssPath = dirname(__DIR__, 3) . '/public/css/typography.css';
            if (!$this->typographyService->writeCssFile($cssPath)) {
                throw new \RuntimeException('Failed to write typography CSS file');
            }

            $_SESSION['flash'][] = [
                'type' => 'success',
                'message' => trans('admin.typography.saved'),
            ];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => trans('admin.typography.save_error') . ': ' . $e->getMessage(),
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/typography'))->withStatus(302);
    }

    /**
     * Reset typography to defaults
     */
    public function reset(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/typography'))->withStatus(302);
        }

        try {
            $this->typographyService->resetToDefaults();

            // Regenerate CSS file
            $cssPath = dirname(__DIR__, 3) . '/public/css/typography.css';
            if (!$this->typographyService->writeCssFile($cssPath)) {
                throw new \RuntimeException('Failed to write typography CSS file');
            }

            $_SESSION['flash'][] = [
                'type' => 'success',
                'message' => trans('admin.typography.reset_success'),
            ];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = [
                'type' => 'danger',
                'message' => trans('admin.typography.reset_error') . ': ' . $e->getMessage(),
            ];
        }

        return $response->withHeader('Location', $this->redirect('/admin/typography'))->withStatus(302);
    }

    /**
     * AJAX: Get preview CSS for live updates
     */
    public function preview(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        // Build temporary typography array
        $tempTypography = [];
        foreach (array_keys(TypographyService::CONTEXTS) as $context) {
            $font = $data["{$context}_font"] ?? TypographyService::CONTEXTS[$context]['default_font'];
            $weight = (int) ($data["{$context}_weight"] ?? TypographyService::CONTEXTS[$context]['default_weight']);
            $tempTypography[$context] = [
                'font' => $font,
                'weight' => $weight,
            ];
        }

        // Generate preview CSS
        $css = ":root {\n";
        foreach ($tempTypography as $context => $config) {
            $fontData = $this->typographyService->getFontBySlug($config['font']);
            if (!$fontData) {
                continue;
            }

            $fontName = $fontData['name'];
            $fallback = ($fontData['type'] ?? 'sans') === 'serif' ? 'Georgia, serif' : 'system-ui, sans-serif';
            $varName = str_replace('_', '-', $context);

            $css .= "  --font-{$varName}: '{$fontName}', {$fallback};\n";
            $css .= "  --font-{$varName}-weight: {$config['weight']};\n";
        }
        $css .= "}\n";

        $response->getBody()->write($css);
        return $response->withHeader('Content-Type', 'text/css');
    }

    /**
     * AJAX: Get font info
     */
    public function fontInfo(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $fontData = $this->typographyService->getFontBySlug($slug);

        if (!$fontData) {
            $response->getBody()->write(json_encode(['error' => 'Font not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode($fontData));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
