<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Services\CustomFieldService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CustomFieldTypesController extends BaseController
{
    private CustomFieldService $customFieldService;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
        $this->customFieldService = new CustomFieldService($this->db->pdo());
    }

    public function index(Request $request, Response $response): Response
    {
        try {
            $types = $this->customFieldService->getFieldTypes(includeSystem: false);
        } catch (\Throwable $e) {
            // Tables don't exist yet - show migration required message
            if (str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), "doesn't exist")) {
                return $this->view->render($response, 'admin/custom_field_types/index.twig', [
                    'types' => [],
                    'migration_required' => true
                ]);
            }
            throw $e;
        }

        return $this->view->render($response, 'admin/custom_field_types/index.twig', [
            'types' => $types
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/custom_field_types/create.twig', [
            'icons' => $this->customFieldService->getAvailableIcons(),
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/create')->withStatus(302);
        }

        $data = (array)$request->getParsedBody();

        $rawName = trim($data['name'] ?? '');
        $label = trim($data['label'] ?? '');

        // Validate name format (must be lowercase letters, numbers, underscores only)
        if ($rawName !== '' && !preg_match('/^[a-z0-9_]+$/', $rawName)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.name_invalid')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/create')->withStatus(302);
        }

        $name = $rawName;

        if ($name === '' || $label === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.name_required')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/create')->withStatus(302);
        }

        // Check if name already exists
        if ($this->customFieldService->getFieldTypeByName($name)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.name_exists')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/create')->withStatus(302);
        }

        $fieldType = $data['field_type'] ?? 'select';
        if (!in_array($fieldType, ['text', 'select', 'multi_select'])) {
            $fieldType = 'select';
        }

        // Validate icon against allowed list
        $icon = $data['icon'] ?? 'fa-tag';
        $allowedIcons = array_keys($this->customFieldService->getAvailableIcons());
        if (!in_array($icon, $allowedIcons)) {
            $icon = 'fa-tag';
        }

        try {
            $this->customFieldService->createFieldType([
                'name' => $name,
                'label' => $label,
                'icon' => $icon,
                'field_type' => $fieldType,
                'description' => trim($data['description'] ?? ''),
                'show_in_lightbox' => isset($data['show_in_lightbox']) ? 1 : 0,
                'show_in_gallery' => isset($data['show_in_gallery']) ? 1 : 0,
                'sort_order' => (int)($data['sort_order'] ?? 0)
            ]);

            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.custom_fields.created')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/create')->withStatus(302);
        }

        return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $type = $this->customFieldService->getFieldType($id);

        if (!$type) {
            return $response->withStatus(404);
        }

        // Cannot edit system types
        if ($type['is_system']) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.cannot_edit_system')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
        }

        return $this->view->render($response, 'admin/custom_field_types/edit.twig', [
            'type' => $type,
            'icons' => $this->customFieldService->getAvailableIcons(),
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/' . $id . '/edit')->withStatus(302);
        }

        $type = $this->customFieldService->getFieldType($id);
        if (!$type || $type['is_system']) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.cannot_edit_system')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $label = trim($data['label'] ?? '');

        if ($label === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.label_required')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types/' . $id . '/edit')->withStatus(302);
        }

        $fieldType = $data['field_type'] ?? 'select';
        if (!in_array($fieldType, ['text', 'select', 'multi_select'])) {
            $fieldType = 'select';
        }

        // Validate icon against allowed list
        $icon = $data['icon'] ?? 'fa-tag';
        $allowedIcons = array_keys($this->customFieldService->getAvailableIcons());
        if (!in_array($icon, $allowedIcons)) {
            $icon = 'fa-tag';
        }

        try {
            $this->customFieldService->updateFieldType($id, [
                'label' => $label,
                'icon' => $icon,
                'field_type' => $fieldType,
                'description' => trim($data['description'] ?? ''),
                'show_in_lightbox' => isset($data['show_in_lightbox']) ? 1 : 0,
                'show_in_gallery' => isset($data['show_in_gallery']) ? 1 : 0,
                'sort_order' => (int)($data['sort_order'] ?? 0)
            ]);

            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.custom_fields.updated')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic')];
        }

        return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);

        try {
            if ($this->customFieldService->deleteFieldType($id)) {
                $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.custom_fields.deleted')];
            } else {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.custom_fields.cannot_delete_system')];
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic')];
        }

        return $response->withHeader('Location', $this->basePath . '/admin/custom-field-types')->withStatus(302);
    }
}
