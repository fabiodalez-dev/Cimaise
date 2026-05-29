<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\BaseController;
use App\Services\AlbumEnrichmentService;
use App\Services\FeedService;
use App\Support\Database;
use DOMDocument;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public syndication feeds of recently published albums.
 *
 *   GET /feed.xml   -> RSS 2.0
 *   GET /feed/atom  -> Atom 1.0
 *
 * Built with DOMDocument so every value is escaped correctly. Privacy is
 * delegated to FeedService (published, non password-protected, non-NSFW albums).
 */
final class FeedController extends BaseController
{
    private const FEED_ITEMS = 30;

    private FeedService $feed;

    public function __construct(private Database $db)
    {
        parent::__construct();
        $this->feed = new FeedService($db);
    }

    public function rss(Request $request, Response $response): Response
    {
        [$siteTitle, $absBase, $items] = $this->build($request);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $doc->appendChild($rss);

        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);

        $channel->appendChild($this->el($doc, 'title', $siteTitle));
        $channel->appendChild($this->el($doc, 'description', $siteTitle . ' — recent albums'));
        $channel->appendChild($this->el($doc, 'link', $absBase . '/'));
        $channel->appendChild($this->el($doc, 'language', 'en'));

        $self = $doc->createElement('atom:link');
        $self->setAttribute('href', $absBase . '/feed.xml');
        $self->setAttribute('rel', 'self');
        $self->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($self);

        $latest = $this->feed->latestPublishedAt();
        if ($latest !== null) {
            $channel->appendChild($this->el($doc, 'lastBuildDate', $this->rfc822($latest)));
        }

        foreach ($items as $it) {
            $item = $doc->createElement('item');
            $item->appendChild($this->el($doc, 'title', $it['title']));
            $item->appendChild($this->el($doc, 'link', $it['url']));

            $guid = $this->el($doc, 'guid', $it['url']);
            $guid->setAttribute('isPermaLink', 'true');
            $item->appendChild($guid);

            if ($it['date'] !== null) {
                $item->appendChild($this->el($doc, 'pubDate', $this->rfc822($it['date'])));
            }

            // Description as CDATA: optional cover image + excerpt.
            $html = '';
            if ($it['cover'] !== '') {
                $html .= '<p><img src="' . htmlspecialchars($it['cover'], ENT_QUOTES) . '" alt="' . htmlspecialchars($it['title'], ENT_QUOTES) . '"></p>';
            }
            if ($it['excerpt'] !== '') {
                $html .= '<p>' . htmlspecialchars($it['excerpt'], ENT_QUOTES) . '</p>';
            }
            $desc = $doc->createElement('description');
            $desc->appendChild($doc->createCDATASection($html !== '' ? $html : $it['title']));
            $item->appendChild($desc);

            if ($it['cover'] !== '') {
                $enc = $doc->createElement('enclosure');
                $enc->setAttribute('url', $it['cover']);
                $enc->setAttribute('type', 'image/jpeg');
                $enc->setAttribute('length', '0');
                $item->appendChild($enc);
            }

            $channel->appendChild($item);
        }

        return $this->xmlResponse($response, (string) $doc->saveXML(), 'application/rss+xml');
    }

    public function atom(Request $request, Response $response): Response
    {
        [$siteTitle, $absBase, $items] = $this->build($request);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $feed = $doc->createElement('feed');
        $feed->setAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $doc->appendChild($feed);

        $feed->appendChild($this->el($doc, 'title', $siteTitle));
        $feed->appendChild($this->el($doc, 'id', $absBase . '/'));

        $self = $doc->createElement('link');
        $self->setAttribute('href', $absBase . '/feed/atom');
        $self->setAttribute('rel', 'self');
        $feed->appendChild($self);

        $alt = $doc->createElement('link');
        $alt->setAttribute('href', $absBase . '/');
        $feed->appendChild($alt);

        $latest = $this->feed->latestPublishedAt();
        $feed->appendChild($this->el($doc, 'updated', $this->rfc3339($latest)));

        foreach ($items as $it) {
            $entry = $doc->createElement('entry');
            $entry->appendChild($this->el($doc, 'title', $it['title']));
            $entry->appendChild($this->el($doc, 'id', $it['url']));

            $l = $doc->createElement('link');
            $l->setAttribute('href', $it['url']);
            $entry->appendChild($l);

            $entry->appendChild($this->el($doc, 'updated', $this->rfc3339($it['date'])));

            $summary = $doc->createElement('summary');
            $summary->setAttribute('type', 'text');
            $summary->appendChild($doc->createTextNode($it['excerpt'] !== '' ? $it['excerpt'] : $it['title']));
            $entry->appendChild($summary);

            $feed->appendChild($entry);
        }

        return $this->xmlResponse($response, (string) $doc->saveXML(), 'application/atom+xml');
    }

    // --- shared -------------------------------------------------------------

    /**
     * @return array{0:string,1:string,2:array<int,array{title:string,url:string,excerpt:string,cover:string,date:?string}>}
     */
    private function build(Request $request): array
    {
        $uri = $request->getUri();
        $absBase = $uri->getScheme() . '://' . $uri->getAuthority() . $this->basePath;

        $albums = $this->feed->recentPublishedAlbums(self::FEED_ITEMS);
        $covers = $this->coverPaths($albums);

        $items = [];
        foreach ($albums as $a) {
            $coverPath = $covers[(int) $a['id']] ?? '';
            $items[] = [
                'title' => (string) $a['title'],
                'url' => $absBase . '/album/' . $a['slug'],
                'excerpt' => trim((string) ($a['excerpt'] ?? '')),
                'cover' => $coverPath !== '' ? $absBase . $coverPath : '',
                'date' => $a['published_at'] !== null ? (string) $a['published_at'] : null,
            ];
        }

        $siteTitle = $this->siteTitle();
        return [$siteTitle, $absBase, $items];
    }

    /**
     * Map album id -> cover preview path (explicit cover, else first image).
     *
     * @param array<int, array<string, mixed>> $albums
     * @return array<int, string>
     */
    private function coverPaths(array $albums): array
    {
        if (empty($albums)) {
            return [];
        }
        $enrich = new AlbumEnrichmentService($this->db->pdo());
        $out = [];

        $explicitIds = array_values(array_filter(array_column($albums, 'cover_image_id')));
        $explicit = $enrich->loadListingCoverImages($explicitIds);
        foreach ($albums as $a) {
            $cid = (int) ($a['cover_image_id'] ?? 0);
            if ($cid && isset($explicit[$cid]) && !empty($explicit[$cid]['preview_path'])) {
                $out[(int) $a['id']] = (string) $explicit[$cid]['preview_path'];
            }
        }

        $missing = array_values(array_filter(array_map(static fn($a) => (int) $a['id'], $albums), static fn($id) => !isset($out[$id])));
        foreach ($enrich->loadFallbackCoverImages($missing) as $albumId => $img) {
            if (!empty($img['preview_path'])) {
                $out[(int) $albumId] = (string) $img['preview_path'];
            }
        }
        return $out;
    }

    private function siteTitle(): string
    {
        try {
            $stmt = $this->db->pdo()->prepare("SELECT value FROM settings WHERE key = 'site_title' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && trim((string) $val) !== '') {
                return (string) $val;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 'Cimaise';
    }

    private function el(DOMDocument $doc, string $name, string $text): \DOMElement
    {
        $el = $doc->createElement($name);
        $el->appendChild($doc->createTextNode($text));
        return $el;
    }

    private function rfc822(string $dbDate): string
    {
        $ts = strtotime($dbDate);
        return date('r', $ts !== false ? $ts : time());
    }

    private function rfc3339(?string $dbDate): string
    {
        $ts = $dbDate !== null ? strtotime($dbDate) : false;
        return date('c', $ts !== false ? $ts : time());
    }

    private function xmlResponse(Response $response, string $xml, string $contentType): Response
    {
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', $contentType . '; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=900')
            ->withStatus(200);
    }
}
