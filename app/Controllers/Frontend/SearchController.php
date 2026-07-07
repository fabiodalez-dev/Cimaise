<?php

declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\BaseController;
use App\Services\AlbumEnrichmentService;
use App\Services\SearchService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Public full-text search results page (GET /search).
 *
 * Ranking and privacy live in {@see SearchService} (published, non
 * password-protected albums only). This controller enriches the ranked albums
 * with the same cover/count/tag data the gallery listing uses, attaches a few
 * matching-photo thumbnails per album, and renders them through the shared
 * _album_card.twig partial so results look native to the rest of the site.
 */
final class SearchController extends BaseController
{
    private const MATCHED_THUMBS_PER_ALBUM = 5;

    private readonly SearchService $search;

    public function __construct(private readonly Database $db, private readonly Twig $view)
    {
        parent::__construct();
        $this->search = new SearchService($db);
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        // Accept both ?q= and the legacy ?search= the header form used.
        $query = trim((string) ($params['q'] ?? $params['search'] ?? ''));
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 12;

        $result = $this->search->search($query, $page, $perPage);

        // Let plugins observe the search (e.g. analytics-pro). Only for real
        // queries, not the empty landing page.
        if ($query !== '') {
            \App\Support\Hooks::doAction('search_performed', $query, (array)($result['albums'] ?? []));
        }

        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();

        $albums = $this->enrichAlbums($result['albums']);
        $albums = $this->attachMatchedPhotos($albums);

        // NSFW cover sanitisation, consistent with the gallery listing.
        $visible = [];
        foreach ($albums as $album) {
            $visible[] = $this->sanitizeAlbumCoverForNsfw($album, $isAdmin, $nsfwConsent);
        }

        $total = (int) $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));

        return $this->view->render($response, 'frontend/search.twig', [
            'query' => $result['query'],
            'albums' => $visible,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_query' => $query !== '',
            'is_admin' => $isAdmin,
            'nsfw_consent' => $nsfwConsent,
        ]);
    }

    /**
     * Mirror GalleriesController::enrichAlbumsBatch: covers (explicit + fallback),
     * image counts and tags, then drop the raw password hash.
     *
     * @param array<int, array<string, mixed>> $albums
     * @return array<int, array<string, mixed>>
     */
    private function enrichAlbums(array $albums): array
    {
        if ($albums === []) {
            return [];
        }

        $enrich = new AlbumEnrichmentService($this->db->pdo());
        $albumIds = array_column($albums, 'id');

        $byId = [];
        foreach ($albums as $album) {
            $album['cover_image'] = null;
            $album['images_count'] = 0;
            $album['tags'] = [];
            $byId[(int) $album['id']] = $album;
        }

        $coverIds = array_values(array_filter(array_column($albums, 'cover_image_id')));
        $covers = $enrich->loadListingCoverImages($coverIds);
        foreach ($byId as &$album) {
            $cid = (int) ($album['cover_image_id'] ?? 0);
            if ($cid && isset($covers[$cid])) {
                $album['cover_image'] = $covers[$cid];
            }
        }
        unset($album);

        $needsFallback = array_keys(array_filter($byId, static fn ($a) => empty($a['cover_image'])));
        foreach ($enrich->loadFallbackCoverImages($needsFallback) as $albumId => $img) {
            if (isset($byId[$albumId]) && empty($byId[$albumId]['cover_image'])) {
                $byId[$albumId]['cover_image'] = $img;
            }
        }

        foreach ($enrich->loadImageCounts($albumIds) as $albumId => $cnt) {
            if (isset($byId[$albumId])) {
                $byId[$albumId]['images_count'] = $cnt;
            }
        }
        foreach ($enrich->loadTags($albumIds) as $albumId => $tagList) {
            if (isset($byId[$albumId])) {
                $byId[$albumId]['tags'] = $tagList;
            }
        }

        foreach ($byId as &$album) {
            $album['is_password_protected'] = !empty($album['password_hash']);
            unset($album['password_hash']);
        }
        unset($album);

        // Preserve ranked order.
        $ordered = [];
        foreach ($albums as $album) {
            $ordered[] = $byId[(int) $album['id']];
        }
        return $ordered;
    }

    /**
     * Attach up to MATCHED_THUMBS_PER_ALBUM photo thumbnails (with preview/blur
     * paths) for the photos that matched the query, plus a remaining count.
     * Thumbs are batch-loaded once for the whole page.
     *
     * @param array<int, array<string, mixed>> $albums
     * @return array<int, array<string, mixed>>
     */
    private function attachMatchedPhotos(array $albums): array
    {
        $wantedByAlbum = [];
        $allIds = [];
        foreach ($albums as $album) {
            $ids = array_slice($album['matched_image_ids'] ?? [], 0, self::MATCHED_THUMBS_PER_ALBUM);
            $wantedByAlbum[(int) $album['id']] = $ids;
            foreach ($ids as $id) {
                $allIds[] = (int) $id;
            }
        }

        $thumbs = [];
        if ($allIds !== []) {
            $enrich = new AlbumEnrichmentService($this->db->pdo());
            $thumbs = $enrich->loadListingCoverImages(array_values(array_unique($allIds)));
        }

        foreach ($albums as &$album) {
            $matchedAll = $album['matched_image_ids'] ?? [];
            $shownIds = $wantedByAlbum[(int) $album['id']] ?? [];
            $photos = [];
            foreach ($shownIds as $id) {
                if (isset($thumbs[(int) $id])) {
                    $photos[] = $thumbs[(int) $id];
                }
            }
            $album['matched_photos'] = $photos;
            $album['matched_photos_extra'] = max(0, count($matchedAll) - count($photos));
        }
        unset($album);

        return $albums;
    }
}
