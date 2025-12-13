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

        return $this->view->render($response, 'admin/texts/index.twig', [
            'grouped' => $grouped,
            'contexts' => $contexts,
            'search' => $search,
            'current_context' => $context,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    /**
     * Edit a single text
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
        $data = (array)$request->getParsedBody();

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
}
