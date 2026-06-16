<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController extends BaseController
{
    public function __construct(private readonly Database $db, private readonly Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        if (!isset($_SESSION['admin_id'])) {
            return $response->withHeader('Location', $this->redirect('/admin/login'))->withStatus(302);
        }

        return $this->view->render($response, 'admin/dashboard.twig', [
            'userId' => $_SESSION['admin_id'],
            'userName' => $_SESSION['admin_name'] ?? '',
            'stats' => $this->gatherStats(),
            'recentAlbums' => $this->recentAlbums(),
        ]);
    }

    /**
     * Real counts for the dashboard KPI blocks. Defensive: any failure degrades
     * to zeros rather than breaking the page.
     *
     * @return array<string, int|string>
     */
    private function gatherStats(): array
    {
        $pdo = $this->db->pdo();
        $count = function (string $sql) use ($pdo): int {
            try {
                return (int) $pdo->query($sql)->fetchColumn();
            } catch (\Throwable) {
                return 0;
            }
        };

        $albums = $count('SELECT COUNT(*) FROM albums');
        $published = $count('SELECT COUNT(*) FROM albums WHERE is_published = 1');
        $bytes = 0;
        try {
            $bytes = (int) $pdo->query('SELECT COALESCE(SUM(size_bytes), 0) FROM image_variants')->fetchColumn();
        } catch (\Throwable) {
            $bytes = 0;
        }

        return [
            'albums' => $albums,
            'published' => $published,
            'drafts' => max(0, $albums - $published),
            'photos' => $count('SELECT COUNT(*) FROM images'),
            'categories' => $count('SELECT COUNT(*) FROM categories'),
            'collections' => $count('SELECT COUNT(*) FROM collections'),
            'tags' => $count('SELECT COUNT(*) FROM tags'),
            'storage_bytes' => $bytes,
            'storage_human' => $this->humanBytes($bytes),
        ];
    }

    /**
     * Latest albums (most recently updated) with a small cover thumbnail.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentAlbums(int $limit = 6): array
    {
        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->prepare(
                'SELECT a.id, a.title, a.slug, a.is_published,
                        COALESCE(a.updated_at, a.created_at) AS touched_at,
                        iv.path AS cover_path
                 FROM albums a
                 LEFT JOIN images i ON i.id = a.cover_image_id
                 LEFT JOIN image_variants iv ON iv.id = (
                     SELECT iv2.id FROM image_variants iv2
                     WHERE iv2.image_id = i.id AND iv2.variant = \'sm\'
                     ORDER BY CASE iv2.format WHEN \'jpg\' THEN 1 WHEN \'webp\' THEN 2 WHEN \'avif\' THEN 3 ELSE 4 END, iv2.id
                     LIMIT 1
                 )
                 ORDER BY touched_at DESC, a.id DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }
        $mb = $bytes / 1048576;
        if ($mb < 1024) {
            return number_format($mb, $mb < 10 ? 1 : 0, ',', '.') . ' MB';
        }
        return number_format($mb / 1024, 1, ',', '.') . ' GB';
    }
}
