<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\TranslationService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TextsController extends BaseController
{
    private TranslationService $translations;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
        $this->translations = new TranslationService($this->db);
    }

    /**
     * List all frontend texts grouped by context
     */
    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $search = trim((string)($queryParams['search'] ?? ''));
        $context = trim((string)($queryParams['context'] ?? ''));

        if ($search !== '') {
            $texts = $this->translations->search($search);
            // Group results by context
            $grouped = [];
            foreach ($texts as $text) {
                $ctx = $text['context'] ?? 'general';
                if (!isset($grouped[$ctx])) {
                    $grouped[$ctx] = [];
                }
                $grouped[$ctx][] = $text;
            }
        } else {
            $grouped = $this->translations->allGrouped();
        }

        // Filter by context if specified
        if ($context !== '' && isset($grouped[$context])) {
            $grouped = [$context => $grouped[$context]];
        }

        $contexts = $this->translations->getContexts();

        // Get available language files for the dropdown (server-side)
        $languages = $this->getAvailableLanguages();

        return $this->view->render($response, 'admin/texts/index.twig', [
            'grouped' => $grouped,
            'contexts' => $contexts,
            'search' => $search,
            'current_context' => $context,
            'csrf' => $_SESSION['csrf'] ?? '',
            'languages' => $languages
        ]);
    }

    /**
     * Edit a single text
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $text = $this->translations->find($id);

        if (!$text) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Text not found.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $contexts = $this->translations->getContexts();

        return $this->view->render($response, 'admin/texts/edit.twig', [
            'text' => $text,
            'contexts' => $contexts,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    /**
     * Update a text
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts/' . $id . '/edit'))->withStatus(302);
        }

        $text = $this->translations->find($id);
        if (!$text) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Text not found.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $this->translations->update($id, [
            'text_value' => (string)($data['text_value'] ?? ''),
            'context' => (string)($data['context'] ?? 'general'),
            'description' => (string)($data['description'] ?? '')
        ]);

        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Text updated successfully.'];
        return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
    }

    /**
     * Show create form
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function create(Request $request, Response $response): Response
    {
        $contexts = $this->translations->getContexts();

        return $this->view->render($response, 'admin/texts/create.twig', [
            'contexts' => $contexts,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    /**
     * Store a new text
     */
    public function store(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts/create'))->withStatus(302);
        }

        $key = trim((string)($data['text_key'] ?? ''));
        if ($key === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Text key is required.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts/create'))->withStatus(302);
        }

        // Check if key already exists
        $existing = $this->translations->findByKey($key);
        if ($existing) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'A text with this key already exists.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts/create'))->withStatus(302);
        }

        $this->translations->create([
            'text_key' => $key,
            'text_value' => (string)($data['text_value'] ?? ''),
            'context' => (string)($data['context'] ?? 'general'),
            'description' => (string)($data['description'] ?? '')
        ]);

        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Text created successfully.'];
        return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
    }

    /**
     * Delete a text
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $text = $this->translations->find($id);
        if (!$text) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Text not found.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $this->translations->delete($id);

        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Text deleted successfully.'];
        return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
    }

    /**
     * Inline update (AJAX)
     */
    public function inlineUpdate(Request $request, Response $response, array $args): Response
    {
        // Validate CSRF token from header
        $csrf = $request->getHeaderLine('X-CSRF-Token');
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Invalid CSRF token']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $id = (int)($args['id'] ?? 0);
        $data = json_decode((string)$request->getBody(), true) ?: [];

        $text = $this->translations->find($id);
        if (!$text) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Text not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $this->translations->update($id, [
            'text_value' => (string)($data['text_value'] ?? $text['text_value']),
            'context' => $text['context'],
            'description' => $text['description']
        ]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Seed default translations
     */
    public function seed(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $defaults = TranslationService::getDefaults();
        $added = 0;

        foreach ($defaults as $item) {
            [$key, $value, $context, $description] = $item;
            $existing = $this->translations->findByKey($key);
            if (!$existing) {
                $this->translations->create([
                    'text_key' => $key,
                    'text_value' => $value,
                    'context' => $context,
                    'description' => $description
                ]);
                $added++;
            }
        }

        $_SESSION['flash'][] = ['type' => 'success', 'message' => "Seeded {$added} new translations."];
        return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
    }

    /**
     * Get available language files
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function languages(Request $request, Response $response): Response
    {
        $translationsDir = dirname(__DIR__, 3) . '/storage/translations';
        $languages = [];

        if (is_dir($translationsDir)) {
            foreach (glob($translationsDir . '/*.json') as $file) {
                $content = @file_get_contents($file);
                if ($content) {
                    $data = json_decode($content, true);
                    $meta = $data['_meta'] ?? [];
                    $languages[] = [
                        'code' => $meta['code'] ?? pathinfo($file, PATHINFO_FILENAME),
                        'name' => $meta['language'] ?? pathinfo($file, PATHINFO_FILENAME),
                        'file' => basename($file),
                        'version' => $meta['version'] ?? '1.0.0'
                    ];
                }
            }
        }

        $response->getBody()->write(json_encode(['languages' => $languages]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Import translations from a preset JSON file
     */
    public function import(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $langCode = trim((string)($data['language'] ?? ''));
        $mode = (string)($data['mode'] ?? 'merge'); // merge or replace

        if ($langCode === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Please select a language.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        // Sanitize language code to prevent path traversal
        $langCode = preg_replace('/[^a-z0-9_-]/i', '', $langCode);
        $filePath = dirname(__DIR__, 3) . '/storage/translations/' . $langCode . '.json';

        if (!file_exists($filePath)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Language file not found.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $result = $this->importFromJsonFile($filePath, $mode);

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => "Imported {$result['added']} new, updated {$result['updated']} existing translations."
        ];
        return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
    }

    /**
     * Upload and import a custom JSON file
     */
    public function upload(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $csrf = (string)($data['csrf'] ?? '');

        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $uploadedFile = $uploadedFiles['json_file'] ?? null;

        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Please upload a valid JSON file.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        // Check file extension
        $filename = $uploadedFile->getClientFilename();
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'json') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Only JSON files are allowed.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        // Save to temp and import
        $tempPath = sys_get_temp_dir() . '/' . uniqid('translation_') . '.json';
        $uploadedFile->moveTo($tempPath);

        // Validate JSON
        $content = file_get_contents($tempPath);
        $jsonData = json_decode($content, true);

        if ($jsonData === null) {
            unlink($tempPath);
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid JSON file.'];
            return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
        }

        $mode = (string)($data['mode'] ?? 'merge');
        $result = $this->importFromJsonFile($tempPath, $mode);

        // Optionally save to storage/translations
        $saveFile = !empty($data['save_file']);
        if ($saveFile && isset($jsonData['_meta']['code'])) {
            $langCode = preg_replace('/[^a-z0-9_-]/i', '', $jsonData['_meta']['code']);
            $destPath = dirname(__DIR__, 3) . '/storage/translations/' . $langCode . '.json';
            copy($tempPath, $destPath);
        }

        unlink($tempPath);

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => "Imported {$result['added']} new, updated {$result['updated']} existing translations."
        ];
        return $response->withHeader('Location', $this->redirect('/admin/texts'))->withStatus(302);
    }

    /**
     * Export current translations as JSON
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function export(Request $request, Response $response): Response
    {
        $grouped = $this->translations->allGrouped();

        $export = [
            '_meta' => [
                'language' => 'Custom',
                'code' => 'custom',
                'version' => '1.0.0',
                'exported_at' => date('Y-m-d H:i:s')
            ]
        ];

        foreach ($grouped as $context => $texts) {
            $export[$context] = [];
            foreach ($texts as $text) {
                $export[$context][$text['text_key']] = $text['text_value'];
            }
        }

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="translations-' . date('Y-m-d') . '.json"');
    }

    /**
     * Import translations from a JSON file path
     */
    private function importFromJsonFile(string $filePath, string $mode = 'merge'): array
    {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (!$data) {
            return ['added' => 0, 'updated' => 0];
        }

        // Normalize mode to valid values
        $validModes = ['merge', 'replace', 'skip'];
        if (!\in_array($mode, $validModes, true)) {
            $mode = 'merge';
        }

        $added = 0;
        $updated = 0;
        $pdo = $this->db->pdo();

        // Wrap entire operation in transaction for data consistency
        $pdo->beginTransaction();

        try {
            // If mode is replace, delete all existing translations first
            if ($mode === 'replace') {
                $pdo->exec('DELETE FROM frontend_texts');
            }

            foreach ($data as $context => $translations) {
                if ($context === '_meta') {
                    continue;
                }

                if (!\is_array($translations)) {
                    continue;
                }

                // Sanitize context: alphanumeric, underscore, hyphen only, max 50 chars
                $context = preg_replace('/[^a-z0-9_-]/i', '', (string)$context);
                $context = mb_substr($context, 0, 50);
                if ($context === '') {
                    $context = 'general';
                }

                foreach ($translations as $key => $value) {
                    if (!\is_string($value)) {
                        continue;
                    }

                    $existing = $this->translations->findByKey($key);

                    if ($existing) {
                        if ($mode !== 'skip') {
                            $this->translations->update($existing['id'], [
                                'text_value' => $value,
                                'context' => $context,
                                'description' => $existing['description']
                            ]);
                            $updated++;
                        }
                    } else {
                        $this->translations->create([
                            'text_key' => $key,
                            'text_value' => $value,
                            'context' => $context,
                            'description' => ''
                        ]);
                        $added++;
                    }
                }
            }

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /**
     * Get available language files from storage/translations
     */
    private function getAvailableLanguages(): array
    {
        $translationsDir = dirname(__DIR__, 3) . '/storage/translations';
        $languages = [];

        if (is_dir($translationsDir)) {
            foreach (glob($translationsDir . '/*.json') as $file) {
                $content = @file_get_contents($file);
                if ($content) {
                    $data = json_decode($content, true);
                    $meta = $data['_meta'] ?? [];
                    $languages[] = [
                        'code' => $meta['code'] ?? pathinfo($file, PATHINFO_FILENAME),
                        'name' => $meta['language'] ?? pathinfo($file, PATHINFO_FILENAME),
                        'file' => basename($file),
                        'version' => $meta['version'] ?? '1.0.0'
                    ];
                }
            }
        }

        return $languages;
    }
}
