<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AlbumLayoutService;
use App\Support\Database;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

final class AlbumLayoutController extends BaseController
{
    public function __construct(private readonly Database $db)
    {
        parent::__construct();
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $response = $response->withHeader('Cache-Control', 'private, no-store');
        if (!$this->validateCsrf($request)) {
            return $this->jsonResponse($response, [
                'error' => trans('admin.flash.csrf_invalid'),
            ], 403);
        }

        $albumId = (int) $args['id'];
        if ($this->db->query('SELECT 1 FROM albums WHERE id = ?', [$albumId])->fetchColumn() === false) {
            return $this->jsonResponse($response, ['error' => trans('admin.flash.album_not_found')], 404);
        }

        $body = (array) $request->getParsedBody();
        $revision = $body['revision'] ?? null;
        $action = $body['action'] ?? null;
        $layout = $body['layout'] ?? null;
        if (!is_int($revision) || $revision < 0 || !is_string($action)
            || ($action !== 'default' && !is_array($layout))) {
            return $this->invalidLayout($response);
        }

        $service = new AlbumLayoutService($this->db);
        $imageIds = $action === 'default' ? null : array_map('intval', $this->db
            ->query('SELECT id FROM images WHERE album_id = ?', [$albumId])
            ->fetchAll(\PDO::FETCH_COLUMN));
        $savedLayout = null;
        try {
            $revision = $service->save($albumId, $revision, $action === 'default' ? null : $layout, $action, $imageIds, $savedLayout);
        } catch (InvalidArgumentException | JsonException) {
            return $this->invalidLayout($response);
        } catch (RuntimeException $error) {
            return match ($error->getCode()) {
                404 => $this->jsonResponse($response, ['error' => trans('admin.flash.album_not_found')], 404),
                409 => $this->jsonResponse($response, ['error' => trans('admin.layout.stale')], 409),
                default => throw $error,
            };
        }

        return $this->jsonResponse($response, [
            'ok' => true,
            'revision' => $revision,
            'layout' => $savedLayout,
        ]);
    }

    private function invalidLayout(Response $response): Response
    {
        return $this->jsonResponse($response, ['error' => trans('admin.layout.invalid_layout')], 422);
    }
}
