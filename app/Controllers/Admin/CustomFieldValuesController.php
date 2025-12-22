<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Services\CustomFieldService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CustomFieldValuesController extends BaseController
{
    private CustomFieldService $customFieldService;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
        $this->customFieldService = new CustomFieldService($this->db->pdo());
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $typeId = (int)($args['type_id'] ?? 0);
        $type = $this->customFieldService->getFieldType($typeId);

        if (!$type) {
            return $response->withStatus(404);
        }

        // Cannot manage values for system types (they use their own tables)
        if ($type['is_system']) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.system_uses_own_table')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
        }

        // Text fields don't have predefined values
        if ($type['field_type'] === 'text') {
            $_SESSION['flash'][] = ['type' => 'info', 'message' => trans('admin.custom_fields.text_no_values')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
        }

        $values = $this->customFieldService->getFieldValues($typeId);

        return $this->view->render($response, 'admin/custom_field_values/index.twig', [
            'type' => $type,
            'values' => $values,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response, array $args): Response
    {
        $typeId = (int)($args['type_id'] ?? 0);

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/' . $typeId . '/values')->withStatus(302);
        }

        $type = $this->customFieldService->getFieldType($typeId);
        if (!$type || $type['is_system']) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.invalid_type')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $value = trim($data['value'] ?? '');

        if ($value === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.value_required')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/' . $typeId . '/values')->withStatus(302);
        }

        try {
            $this->customFieldService->createFieldValue(
                $typeId,
                $value,
                trim($data['extra_data'] ?? '') ?: null,
                (int)($data['sort_order'] ?? 0)
            );

            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.custom_fields.value_added')];
        } catch (\Throwable $e) {
            // Check for duplicate
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.value_exists')];
            } else {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: ' . $e->getMessage()];
            }
        }

        return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/' . $typeId . '/values')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $typeId = (int)($args['type_id'] ?? 0);
        $id = (int)($args['id'] ?? 0);

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/' . $typeId . '/values')->withStatus(302);
        }

        try {
            $this->customFieldService->deleteFieldValue($id);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.custom_fields.value_deleted')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/' . $typeId . '/values')->withStatus(302);
    }

    public function updateOrder(Request $request, Response $response, array $args): Response
    {
        $typeId = (int)($args['type_id'] ?? 0);

        if (!$this->validateCsrf($request)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid CSRF token']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $data = (array)$request->getParsedBody();
        $order = $data['order'] ?? [];

        if (!is_array($order)) {
            $response->getBody()->write(json_encode(['error' => 'Invalid order data']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE custom_field_values SET sort_order = ? WHERE id = ? AND field_type_id = ?');

            foreach ($order as $position => $valueId) {
                $stmt->execute([(int)$position, (int)$valueId, $typeId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $response->getBody()->write(json_encode(['error' => 'Failed to update order: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
