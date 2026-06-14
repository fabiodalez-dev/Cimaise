<?php

declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\BaseController;
use App\Services\CollectionService;
use App\Services\ImageVariantsService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public pages for curated collections: /collections (list) and
 * /collection/{slug} (a gallery of the hand-picked photos).
 *
 * Privacy: even though a curator may add any photo, the public pages only ever
 * surface photos whose source album is published, not password-protected and
 * not NSFW. A photo whose album later becomes private silently drops out of the
 * collection rather than leaking through it.
 */
final class CollectionController extends BaseController
{
    private CollectionService $service;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
        $this->service = new CollectionService($db);
    }

    public function index(Request $request, Response $response): Response
    {
        $collections = $this->attachCovers($this->service->publishedCollections());

        return $this->view->render($response, 'frontend/collection_index.twig', [
            'collections' => $collections,
            'page_title' => trans('collection.index_title', [], 'Collections'),
        ]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        $collection = $this->service->findPublishedBySlug($slug);

        if ($collection === null) {
            return $this->view->render($response->withStatus(404), 'frontend/404.twig', [
                'page_title' => '404 — Collection not found',
            ]);
        }

        $images = $this->collectionGalleryImages((int) $collection['id']);

        return $this->view->render($response, 'frontend/collection.twig', [
            'collection' => $collection,
            'images' => $images,
            'page_title' => $collection['title'],
        ]);
    }

    /**
     * Visible photos of a collection, shaped for the shared PhotoSwipe gallery
     * (#images-gallery > a.pswp-link): each carries url (thumb), lightbox_url
     * (full), width, height and a caption.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectionGalleryImages(int $collectionId): array
    {
        $rows = $this->service->visibleImageRows($collectionId);
        if (empty($rows)) {
            return [];
        }

        $variantsByImage = ImageVariantsService::eagerLoadVariants($this->db->pdo(), array_column($rows, 'id'));

        $out = [];
        foreach ($rows as $row) {
            $variants = $variantsByImage[(int) $row['id']] ?? [];
            $thumb = $this->pickVariant($variants, ['md', 'sm']);
            $full = $this->pickVariant($variants, ['xl', 'xxl', 'lg']);

            $thumbPath = $thumb['path'] ?? ($full['path'] ?? '');
            $fullPath = $full['path'] ?? ($thumb['path'] ?? '');
            if ($thumbPath === '' || $fullPath === '') {
                continue; // no usable variant -> skip
            }

            $row['url'] = $this->prefix($thumbPath);
            $row['lightbox_url'] = $this->prefix($fullPath);
            $row['width'] = (int) ($full['width'] ?? $row['width'] ?? 1600);
            $row['height'] = (int) ($full['height'] ?? $row['height'] ?? 1067);
            $row['alt'] = $row['alt_text'] ?: '';
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Pick the best jpg variant matching one of the preferred size names, in
     * order; falls back to the largest jpg, then any variant.
     *
     * @param array<int, array<string, mixed>> $variants
     * @param string[] $preferredSizes
     * @return array<string, mixed>|null
     */
    private function pickVariant(array $variants, array $preferredSizes): ?array
    {
        $jpg = array_values(array_filter($variants, static fn ($v) => ($v['format'] ?? '') === 'jpg'));
        foreach ($preferredSizes as $size) {
            foreach ($jpg as $v) {
                if (($v['variant'] ?? '') === $size) {
                    return $v;
                }
            }
        }
        // Variants are ordered width DESC, so the first jpg is the largest.
        return $jpg[0] ?? ($variants[0] ?? null);
    }

    /**
     * Attach a cover thumbnail to each collection row (explicit cover_image_id,
     * else the first visible photo).
     *
     * @param array<int, array<string, mixed>> $collections
     * @return array<int, array<string, mixed>>
     */
    private function attachCovers(array $collections): array
    {
        if (empty($collections)) {
            return [];
        }
        $pdo = $this->db->pdo();
        foreach ($collections as &$c) {
            $coverId = (int) ($c['cover_image_id'] ?? 0);
            if ($coverId > 0) {
                $stmt = $pdo->prepare(
                    "SELECT path FROM image_variants WHERE image_id = ? AND variant = 'md' AND format = 'jpg' LIMIT 1"
                );
                $stmt->execute([$coverId]);
                $path = $stmt->fetchColumn();
                if ($path === false) {
                    $stmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = ? AND format = 'jpg' ORDER BY width DESC LIMIT 1");
                    $stmt->execute([$coverId]);
                    $path = $stmt->fetchColumn();
                }
                $c['cover_url'] = $path !== false ? $this->prefix((string) $path) : '';
            } else {
                // First visible photo of the collection.
                $stmt = $pdo->prepare(
                    "SELECT iv.path
                     FROM collection_images ci
                     JOIN images i ON i.id = ci.image_id
                     JOIN albums a ON a.id = i.album_id
                     LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'md' AND iv.format = 'jpg'
                     WHERE ci.collection_id = ? AND " . CollectionService::ALBUM_VISIBLE . "
                     ORDER BY ci.sort_order ASC, i.id ASC LIMIT 1"
                );
                $stmt->execute([(int) $c['id']]);
                $path = $stmt->fetchColumn();
                $c['cover_url'] = $path ? $this->prefix((string) $path) : '';
            }
        }
        unset($c);
        return $collections;
    }

    private function prefix(string $path): string
    {
        if ($path === '') {
            return '';
        }
        return $path[0] === '/' ? $this->basePath . $path : $path;
    }
}
