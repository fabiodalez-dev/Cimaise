<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Support\Str;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Admin CRUD for curated collections: hand-picked photos that can span any
 * number of albums. The collection itself is title + slug + description +
 * cover + published flag; membership lives in collection_images (ordered).
 *
 * Photo management is AJAX: attach / detach / reorder / set-cover return JSON
 * so the edit page can manipulate the set without full reloads. The picker
 * lists every image across all albums (privacy is enforced only at public
 * render time, in CollectionController, never at curation time).
 */
final class CollectionsController extends BaseController
{
    private const PICKER_PER_PAGE = 24;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $rows = $this->db->pdo()->query(
            "SELECT c.*, COUNT(ci.image_id) AS image_count
             FROM collections c
             LEFT JOIN collection_images ci ON ci.collection_id = c.id
             GROUP BY c.id
             ORDER BY c.sort_order ASC, c.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return $this->view->render($response, 'admin/collections/index.twig', [
            'collections' => $rows,
            'csrf' => $_SESSION['csrf'] ?? '',
            'flash' => $this->pullFlash(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/collections/edit.twig', [
            'collection' => null,
            'images' => [],
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->redirectWith($response, '/admin/collections', 'danger', 'Invalid CSRF token.');
        }

        $data = (array) $request->getParsedBody();
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return $this->redirectWith($response, '/admin/collections/create', 'danger', trans('admin.collections.title_required', [], 'Title is required.'));
        }

        $slug = $this->uniqueSlug((string) ($data['slug'] ?? ''), $title, null);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO collections (title, slug, description, is_published, sort_order)
             VALUES (:title, :slug, :description, :is_published, :sort_order)'
        );
        $stmt->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':description' => trim((string) ($data['description'] ?? '')) ?: null,
            ':is_published' => !empty($data['is_published']) ? 1 : 0,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();

        // Land on edit so the curator can immediately add photos.
        return $this->redirectWith($response, '/admin/collections/' . $id . '/edit', 'success', trans('admin.collections.created', [], 'Collection created.'));
    }

    /** @param array<string, string> $args */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $collection = $this->findCollection($id);
        if ($collection === null) {
            return $this->redirectWith($response, '/admin/collections', 'danger', trans('admin.collections.not_found', [], 'Collection not found.'));
        }

        return $this->view->render($response, 'admin/collections/edit.twig', [
            'collection' => $collection,
            'images' => $this->collectionImages($id),
            'csrf' => $_SESSION['csrf'] ?? '',
            'flash' => $this->pullFlash(),
        ]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->redirectWith($response, '/admin/collections', 'danger', 'Invalid CSRF token.');
        }
        $id = (int) ($args['id'] ?? 0);
        $collection = $this->findCollection($id);
        if ($collection === null) {
            return $this->redirectWith($response, '/admin/collections', 'danger', trans('admin.collections.not_found', [], 'Collection not found.'));
        }

        $data = (array) $request->getParsedBody();
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return $this->redirectWith($response, '/admin/collections/' . $id . '/edit', 'danger', trans('admin.collections.title_required', [], 'Title is required.'));
        }

        $slug = $this->uniqueSlug((string) ($data['slug'] ?? ''), $title, $id);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE collections
             SET title = :title, slug = :slug, description = :description,
                 is_published = :is_published, sort_order = :sort_order,
                 updated_at = ' . $this->db->nowExpression() . '
             WHERE id = :id'
        );
        $stmt->execute([
            ':title' => $title,
            ':slug' => $slug,
            ':description' => trim((string) ($data['description'] ?? '')) ?: null,
            ':is_published' => !empty($data['is_published']) ? 1 : 0,
            ':sort_order' => (int) ($data['sort_order'] ?? 0),
            ':id' => $id,
        ]);

        return $this->redirectWith($response, '/admin/collections/' . $id . '/edit', 'success', trans('admin.collections.updated', [], 'Collection updated.'));
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->redirectWith($response, '/admin/collections', 'danger', 'Invalid CSRF token.');
        }
        $id = (int) ($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('DELETE FROM collections WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $this->redirectWith($response, '/admin/collections', 'success', trans('admin.collections.deleted', [], 'Collection deleted.'));
    }

    // --- AJAX photo management ----------------------------------------------

    /** @param array<string, string> $args */
    public function attach(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'csrf'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $imageId = (int) ($data['image_id'] ?? 0);
        if ($id <= 0 || $imageId <= 0 || $this->findCollection($id) === null) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'invalid'], 422);
        }

        // Append at the end.
        $next = (int) $this->db->pdo()->query(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM collection_images WHERE collection_id = ' . $id
        )->fetchColumn();

        $sql = $this->db->insertIgnoreKeyword()
            . ' INTO collection_images (collection_id, image_id, sort_order) VALUES (:c, :i, :s)';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([':c' => $id, ':i' => $imageId, ':s' => $next]);

        return $this->jsonResponse($response, ['ok' => true, 'count' => $this->imageCount($id)]);
    }

    /** @param array<string, string> $args */
    public function detach(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'csrf'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $imageId = (int) ($data['image_id'] ?? 0);

        $stmt = $this->db->pdo()->prepare('DELETE FROM collection_images WHERE collection_id = :c AND image_id = :i');
        $stmt->execute([':c' => $id, ':i' => $imageId]);

        // If the removed image was the cover, clear it.
        $this->db->pdo()->prepare('UPDATE collections SET cover_image_id = NULL WHERE id = :c AND cover_image_id = :i')
            ->execute([':c' => $id, ':i' => $imageId]);

        return $this->jsonResponse($response, ['ok' => true, 'count' => $this->imageCount($id)]);
    }

    /** @param array<string, string> $args */
    public function reorder(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'csrf'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $order = $data['order'] ?? [];
        if (!is_array($order)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'invalid'], 422);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE collection_images SET sort_order = :s WHERE collection_id = :c AND image_id = :i');
            foreach (array_values($order) as $pos => $imageId) {
                $stmt->execute([':s' => $pos, ':c' => $id, ':i' => (int) $imageId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'failed'], 500);
        }

        return $this->jsonResponse($response, ['ok' => true]);
    }

    /** @param array<string, string> $args */
    public function setCover(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'csrf'], 403);
        }
        $id = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $imageId = (int) ($data['image_id'] ?? 0);

        // Only allow a cover that actually belongs to the collection.
        $check = $this->db->pdo()->prepare('SELECT 1 FROM collection_images WHERE collection_id = ? AND image_id = ?');
        $check->execute([$id, $imageId]);
        if ($check->fetchColumn() === false) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'not_in_collection'], 422);
        }

        $this->db->pdo()->prepare('UPDATE collections SET cover_image_id = :i WHERE id = :c')
            ->execute([':i' => $imageId, ':c' => $id]);

        return $this->jsonResponse($response, ['ok' => true]);
    }

    /**
     * Picker: paginated images across all albums, flagged if already in the collection.
     *
     * @param array<string, string> $args
     */
    public function pickerImages(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $albumId = (int) ($q['album'] ?? 0);
        $search = trim((string) ($q['q'] ?? ''));
        $offset = ($page - 1) * self::PICKER_PER_PAGE;

        $wheres = [];
        $params = [];
        if ($albumId > 0) {
            $wheres[] = 'i.album_id = :album';
            $params[':album'] = $albumId;
        }
        if ($search !== '') {
            $wheres[] = '(i.caption LIKE :q OR i.alt_text LIKE :q OR a.title LIKE :q)';
            $params[':q'] = '%' . $search . '%';
        }
        $whereSql = $wheres ? ('WHERE ' . implode(' AND ', $wheres)) : '';

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM images i JOIN albums a ON a.id = i.album_id $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT i.id, i.album_id, i.caption, i.alt_text, a.title AS album_title,
                       iv.path AS preview_path,
                       CASE WHEN ci.image_id IS NULL THEN 0 ELSE 1 END AS in_collection
                FROM images i
                JOIN albums a ON a.id = i.album_id
                LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
                LEFT JOIN collection_images ci ON ci.image_id = i.id AND ci.collection_id = :cid
                $whereSql
                ORDER BY i.id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':cid', $id, PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', self::PICKER_PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->jsonResponse($response, [
            'ok' => true,
            'images' => $images,
            'total' => $total,
            'page' => $page,
            'per_page' => self::PICKER_PER_PAGE,
            'total_pages' => (int) max(1, (int) ceil($total / self::PICKER_PER_PAGE)),
        ]);
    }

    // --- helpers ------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function findCollection(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM collections WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function collectionImages(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.album_id, i.caption, i.alt_text, a.title AS album_title,
                    iv.path AS preview_path, ci.sort_order
             FROM collection_images ci
             JOIN images i ON i.id = ci.image_id
             JOIN albums a ON a.id = i.album_id
             LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
             WHERE ci.collection_id = :c
             ORDER BY ci.sort_order ASC, i.id ASC"
        );
        $stmt->execute([':c' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function imageCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM collection_images WHERE collection_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    /** Generate a unique slug, ignoring $excludeId when updating. */
    private function uniqueSlug(string $candidate, string $fallback, ?int $excludeId): string
    {
        $base = Str::slug($candidate !== '' ? $candidate : $fallback);
        if ($base === '') {
            $base = 'collection';
        }
        $slug = $base;
        $i = 2;
        while ($this->slugTaken($slug, $excludeId)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private function slugTaken(string $slug, ?int $excludeId): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM collections WHERE slug = ? AND id <> ?');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM collections WHERE slug = ?');
            $stmt->execute([$slug]);
        }
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int, array<string, mixed>> */
    private function pullFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return is_array($flash) ? $flash : [];
    }

    private function redirectWith(Response $response, string $path, string $type, string $message): Response
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        // $path is always a literal internal route from this controller, never
        // user input; redirect() only prepends the app base path.
        $location = $this->redirect($path); // nosemgrep
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
