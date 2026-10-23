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
    private readonly TypographyService $typographyService;

    public function uploadFont(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) { return $response->withStatus(400); }
        try {
            $data = (array)$request->getParsedBody();
            $name = trim((string)($data['font_name'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9 -]{0,79}$/D', $name)) {
                throw new \RuntimeException('Use a font name with letters, numbers, spaces or hyphens.');
            }
            $weight = (int)($data['font_weight'] ?? 400);
            if ($weight < 100 || $weight > 900 || $weight % 100 !== 0) {
                throw new \RuntimeException('Choose a weight from 100 to 900.');
            }
            $file = $request->getUploadedFiles()['font_file'] ?? null;
            if (!$file || $file->getError() !== UPLOAD_ERR_OK || ($file->getSize() ?? 0) > 10485760) {
                throw new \RuntimeException('Upload a font file up to 10 MB.');
            }
            $bytes = (string)$file->getStream();
            $ext = strtolower(pathinfo((string)$file->getClientFilename(), PATHINFO_EXTENSION));
            $signatures = ['woff2' => 'wOF2', 'woff' => 'wOFF', 'ttf' => "\x00\x01\x00\x00", 'otf' => 'OTTO'];
            if (strlen($bytes) < 48 || strlen($bytes) > 10485760 || !isset($signatures[$ext]) || !str_starts_with($bytes, $signatures[$ext])) {
                throw new \RuntimeException('Choose a valid WOFF2, WOFF, TTF or OTF font.');
            }
            if (in_array($ext, ['woff', 'woff2'], true) && unpack('Nlength', substr($bytes, 8, 4))['length'] !== strlen($bytes)) {
                throw new \RuntimeException('The font file is incomplete.');
            }
            $filename = hash('sha256', $bytes) . '.' . $ext;
            $directory = dirname(__DIR__, 3) . '/storage/fonts';
            \App\Services\ImagesService::ensureDir($directory);
            if (file_put_contents($directory . '/' . $filename, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('Could not save the font.');
            }
            $settings = new SettingsService($this->db);
            $fonts = (array)$settings->get('typography.custom_fonts', []);
            $slug = 'custom-' . substr(hash('sha256', strtolower($name)), 0, 16);
            $font = $fonts[$slug] ?? ['name' => $name, 'weights' => [], 'files' => [],
                'type' => ($data['font_type'] ?? '') === 'serif' ? 'serif' : 'sans',
                'category' => ($data['font_type'] ?? '') === 'serif' ? 'editorial' : 'clean', 'description' => 'Uploaded font'];
            $font['files'][$weight] = $filename;
            $font['weights'] = array_map(intval(...), array_keys($font['files']));
            sort($font['weights']);
            $fonts[$slug] = $font;
            $settings->set('typography.custom_fonts', $fonts);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.typography.font_uploaded', [], 'Font uploaded. Select it below and save.')];
        } catch (\Throwable $error) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => $error->getMessage()];
        }
        return $response->withHeader('Location', $this->redirect('/admin/typography'))->withStatus(302);
    }

    /** @param array<string, mixed> $args */
    public function serveFont(Request $request, Response $response, array $args): Response
    {
        $filename = (string)($args['filename'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}\.(woff2|woff|ttf|otf)$/D', $filename, $matches)) {
            return $response->withStatus(404);
        }
        $file = dirname(__DIR__, 3) . '/storage/fonts/' . $filename;
        if (!is_file($file) || !($stream = fopen($file, 'rb'))) { return $response->withStatus(404); }
        return $response->withBody(new \Slim\Psr7\Stream($stream))
            ->withHeader('Content-Type', 'font/' . $matches[1])
            ->withHeader('Content-Length', (string)filesize($file))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }

    public function __construct(private readonly Database $db, private readonly Twig $view)
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
        if (!$this->validateCsrf($request)) {
            $response->getBody()->write('/* CSRF validation failed */');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/css');
        }

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
