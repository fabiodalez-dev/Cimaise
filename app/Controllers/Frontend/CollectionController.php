<?php

declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\BaseController;
use App\Services\BaseUrlService;
use App\Services\CollectionService;
use App\Services\ImageVariantsService;
use App\Services\SettingsService;
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
    private readonly CollectionService $service;

    public function __construct(private readonly Database $db, private readonly Twig $view)
    {
        parent::__construct();
        $this->service = new CollectionService($db);
    }

    public function index(Request $request, Response $response): Response
    {
        $collections = $this->attachCovers($this->service->publishedCollections());

        [$siteName, $canonicalBase, $root] = $this->seoBase($request);
        $title = trans('collection.index_title', [], 'Collections');
        // First collection cover as a representative OG image, if any. cover_url
        // already carries the base path, so it is made absolute against the ORIGIN.
        $metaImage = '';
        foreach ($collections as $c) {
            if (!empty($c['cover_url'])) {
                $metaImage = $this->absoluteUrl((string) $c['cover_url'], $root);
                break;
            }
        }

        return $this->view->render($response, 'frontend/collection_index.twig', [
            'collections' => $collections,
            'page_title' => $title . ' — ' . $siteName,
            'meta_description' => trans('collection.index_description', [], 'Curated photo collections.'),
            'meta_image' => $metaImage,
            'canonical_url' => $canonicalBase . '/collections',
            'current_url' => (string) $request->getUri(),
            'canonical_base' => $canonicalBase,
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

        [$siteName, $canonicalBase, $root] = $this->seoBase($request);
        $description = trim(strip_tags((string) ($collection['description'] ?? $collection['excerpt'] ?? '')));
        // OG image: the first visible photo of the collection. lightbox_url
        // already carries the base path, so it is made absolute against the ORIGIN.
        $metaImage = '';
        if ($images !== [] && !empty($images[0]['lightbox_url'])) {
            $metaImage = $this->absoluteUrl((string) $images[0]['lightbox_url'], $root);
        }

        return $this->view->render($response, 'frontend/collection.twig', [
            'collection' => $collection,
            'images' => $images,
            'page_title' => $collection['title'] . ' — ' . $siteName,
            'meta_description' => $description,
            'meta_image' => $metaImage,
            'canonical_url' => $canonicalBase . '/collection/' . ($collection['slug'] ?? $slug),
            'current_url' => (string) $request->getUri(),
            'canonical_base' => $canonicalBase,
        ]);
    }

    /**
     * Site name + request-accurate absolute site base (incl. subdirectory).
     * seo.canonical_base_url wins over the detected origin.
     *
     * @return array{0:string,1:string,2:string} [siteName, canonicalBase, root]
     */
    private function seoBase(Request $request): array
    {
        $svc = new SettingsService($this->db);
        $siteName = (string) ($svc->get('seo.site_title', 'Portfolio') ?? 'Portfolio');
        $canonOverride = (string) ($svc->get('seo.canonical_base_url', '') ?? '');

        $roots = BaseUrlService::canonicalRoots($request, $this->basePath, $canonOverride);
        $root = $roots['root'];
        $canonicalBase = $roots['base'];

        return [$siteName, $canonicalBase, $root];
    }

    /**
     * Make an already-base-path-prefixed media URL absolute for OG tags by
     * prepending the ORIGIN (scheme://authority), so the base path is not doubled.
     */
    private function absoluteUrl(string $url, string $root): string
    {
        if ($url === '' || str_starts_with($url, 'http')) {
            return $url;
        }
        return $root . '/' . ltrim($url, '/');
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
        if ($rows === []) {
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
        if ($collections === []) {
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
