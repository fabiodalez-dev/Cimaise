<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\BaseUrlService;
use App\Support\Logger;
use App\Services\SettingsService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SeoController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);
        
        // Get all SEO-related settings
        $settings = [
            // Site-wide SEO
            'site_title' => $svc->get('seo.site_title', 'Photography Portfolio'),
            'site_description' => $svc->get('seo.site_description', 'Professional photography portfolio showcasing creative work and artistic vision'),
            'site_keywords' => $svc->get('seo.site_keywords', 'photography, portfolio, professional photographer, creative photography'),
            'author_name' => $svc->get('seo.author_name', ''),
            'author_url' => $svc->get('seo.author_url', ''),
            'organization_name' => $svc->get('seo.organization_name', ''),
            'organization_url' => $svc->get('seo.organization_url', ''),
            
            // Open Graph & Social Media
            'og_site_name' => $svc->get('seo.og_site_name', 'Photography Portfolio'),
            'og_type' => $svc->get('seo.og_type', 'website'),
            'og_locale' => $svc->get('seo.og_locale', 'en_US'),
            'twitter_card' => $svc->get('seo.twitter_card', 'summary_large_image'),
            'twitter_site' => $svc->get('seo.twitter_site', ''),
            'twitter_creator' => $svc->get('seo.twitter_creator', ''),
            
            // Schema.org & Structured Data
            'schema_enabled' => $svc->get('seo.schema_enabled', 'true') === 'true',
            'breadcrumbs_enabled' => $svc->get('seo.breadcrumbs_enabled', 'true') === 'true',
            'local_business_enabled' => $svc->get('seo.local_business_enabled', 'false') === 'true',
            'local_business_name' => $svc->get('seo.local_business_name', ''),
            'local_business_type' => $svc->get('seo.local_business_type', 'ProfessionalService'),
            'local_business_address' => $svc->get('seo.local_business_address', ''),
            'local_business_city' => $svc->get('seo.local_business_city', ''),
            'local_business_postal_code' => $svc->get('seo.local_business_postal_code', ''),
            'local_business_country' => $svc->get('seo.local_business_country', ''),
            'local_business_phone' => $svc->get('seo.local_business_phone', ''),
            'local_business_geo_lat' => $svc->get('seo.local_business_geo_lat', ''),
            'local_business_geo_lng' => $svc->get('seo.local_business_geo_lng', ''),
            'local_business_opening_hours' => $svc->get('seo.local_business_opening_hours', ''),
            
            // Professional Photographer Schema
            'photographer_job_title' => $svc->get('seo.photographer_job_title', 'Professional Photographer'),
            'photographer_services' => $svc->get('seo.photographer_services', 'Professional Photography Services'),
            'photographer_area_served' => $svc->get('seo.photographer_area_served', ''),
            'photographer_same_as' => $svc->get('seo.photographer_same_as', ''),
            
            // Technical SEO
            'robots_default' => $svc->get('seo.robots_default', 'index,follow'),
            'canonical_base_url' => $svc->get('seo.canonical_base_url', ''),
            'sitemap_enabled' => $svc->get('seo.sitemap_enabled', 'true') === 'true',
            'analytics_gtag' => $svc->get('seo.analytics_gtag', ''),
            'analytics_gtm' => $svc->get('seo.analytics_gtm', ''),
            
            // Image SEO
            'image_alt_auto' => $svc->get('seo.image_alt_auto', 'true') === 'true',
            'image_copyright_notice' => $svc->get('seo.image_copyright_notice', ''),
            'image_license_url' => $svc->get('seo.image_license_url', ''),
            'image_acquire_license_page' => $svc->get('seo.image_acquire_license_page', ''),
            
            // Performance & Crawling
            'preload_critical_images' => $svc->get('seo.preload_critical_images', 'true') === 'true',
            'lazy_load_images' => $svc->get('seo.lazy_load_images', 'true') === 'true',
            'structured_data_format' => $svc->get('seo.structured_data_format', 'json-ld')
        ];
        
        return $this->view->render($response, 'admin/seo/index.twig', [
            'settings' => $settings,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/seo'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $svc = new SettingsService($this->db);

        try {
            // Site-wide SEO settings
            $svc->set('seo.site_title', trim((string)($data['site_title'] ?? '')));
            $svc->set('seo.site_description', trim((string)($data['site_description'] ?? '')));
            $svc->set('seo.site_keywords', trim((string)($data['site_keywords'] ?? '')));
            $svc->set('seo.author_name', trim((string)($data['author_name'] ?? '')));
            $svc->set('seo.author_url', trim((string)($data['author_url'] ?? '')));
            $svc->set('seo.organization_name', trim((string)($data['organization_name'] ?? '')));
            $svc->set('seo.organization_url', trim((string)($data['organization_url'] ?? '')));
            
            // Open Graph & Social Media
            $svc->set('seo.og_site_name', trim((string)($data['og_site_name'] ?? '')));
            $svc->set('seo.og_type', trim((string)($data['og_type'] ?? 'website')));
            $svc->set('seo.og_locale', trim((string)($data['og_locale'] ?? 'en_US')));
            $svc->set('seo.twitter_card', trim((string)($data['twitter_card'] ?? 'summary_large_image')));
            $svc->set('seo.twitter_site', trim((string)($data['twitter_site'] ?? '')));
            $svc->set('seo.twitter_creator', trim((string)($data['twitter_creator'] ?? '')));
            
            // Schema.org & Structured Data
            $svc->set('seo.schema_enabled', isset($data['schema_enabled']) ? 'true' : 'false');
            $svc->set('seo.breadcrumbs_enabled', isset($data['breadcrumbs_enabled']) ? 'true' : 'false');
            $svc->set('seo.local_business_enabled', isset($data['local_business_enabled']) ? 'true' : 'false');
            
            // Local Business Schema (only save if enabled)
            if (isset($data['local_business_enabled'])) {
                $svc->set('seo.local_business_name', trim((string)($data['local_business_name'] ?? '')));
                $svc->set('seo.local_business_type', trim((string)($data['local_business_type'] ?? 'ProfessionalService')));
                $svc->set('seo.local_business_address', trim((string)($data['local_business_address'] ?? '')));
                $svc->set('seo.local_business_city', trim((string)($data['local_business_city'] ?? '')));
                $svc->set('seo.local_business_postal_code', trim((string)($data['local_business_postal_code'] ?? '')));
                $svc->set('seo.local_business_country', trim((string)($data['local_business_country'] ?? '')));
                $svc->set('seo.local_business_phone', trim((string)($data['local_business_phone'] ?? '')));
                $svc->set('seo.local_business_geo_lat', trim((string)($data['local_business_geo_lat'] ?? '')));
                $svc->set('seo.local_business_geo_lng', trim((string)($data['local_business_geo_lng'] ?? '')));
                $svc->set('seo.local_business_opening_hours', trim((string)($data['local_business_opening_hours'] ?? '')));
            }
            
            // Professional Photographer Schema
            $svc->set('seo.photographer_job_title', trim((string)($data['photographer_job_title'] ?? 'Professional Photographer')));
            $svc->set('seo.photographer_services', trim((string)($data['photographer_services'] ?? 'Professional Photography Services')));
            $svc->set('seo.photographer_area_served', trim((string)($data['photographer_area_served'] ?? '')));
            $svc->set('seo.photographer_same_as', trim((string)($data['photographer_same_as'] ?? '')));
            
            // Technical SEO
            $svc->set('seo.robots_default', trim((string)($data['robots_default'] ?? 'index,follow')));
            $svc->set('seo.canonical_base_url', trim((string)($data['canonical_base_url'] ?? '')));
            $svc->set('seo.sitemap_enabled', isset($data['sitemap_enabled']) ? 'true' : 'false');
            $svc->set('seo.analytics_gtag', trim((string)($data['analytics_gtag'] ?? '')));
            $svc->set('seo.analytics_gtm', trim((string)($data['analytics_gtm'] ?? '')));
            
            // Image SEO
            $svc->set('seo.image_alt_auto', isset($data['image_alt_auto']) ? 'true' : 'false');
            $svc->set('seo.image_copyright_notice', trim((string)($data['image_copyright_notice'] ?? '')));
            $svc->set('seo.image_license_url', trim((string)($data['image_license_url'] ?? '')));
            $svc->set('seo.image_acquire_license_page', trim((string)($data['image_acquire_license_page'] ?? '')));
            
            // Performance & Crawling
            $svc->set('seo.preload_critical_images', isset($data['preload_critical_images']) ? 'true' : 'false');
            $svc->set('seo.lazy_load_images', isset($data['lazy_load_images']) ? 'true' : 'false');
            $svc->set('seo.structured_data_format', trim((string)($data['structured_data_format'] ?? 'json-ld')));
            
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'SEO settings saved successfully'];
            
        } catch (\Throwable $e) {
            Logger::error('SeoController::save error', ['error' => $e->getMessage()], 'admin');
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error saving SEO settings: ' . $e->getMessage()];
        }
        
        return $response->withHeader('Location', $this->redirect('/admin/seo'))->withStatus(302);
    }

    public function generateSitemap(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/seo'))->withStatus(302);
        }

        try {
            // Get published albums for sitemap
            $pdo = $this->db->pdo();
            $stmt = $pdo->query('
                SELECT a.slug, a.updated_at, a.published_at 
                FROM albums a 
                WHERE a.is_published = 1 
                ORDER BY a.published_at DESC
            ');
            $albums = $stmt->fetchAll() ?: [];

            // Get categories for sitemap
            $stmt = $pdo->query('
                SELECT c.slug, MAX(a.updated_at) as last_modified 
                FROM categories c 
                LEFT JOIN albums a ON a.category_id = c.id AND a.is_published = 1
                GROUP BY c.id, c.slug
                ORDER BY c.sort_order, c.name
            ');
            $categories = $stmt->fetchAll() ?: [];

            // Generate sitemap XML
            $svc = new SettingsService($this->db);
            $seoBaseUrl = $svc->get('seo.canonical_base_url', '');
            $seoBaseUrl = is_string($seoBaseUrl) ? trim($seoBaseUrl) : '';

            // Use SEO canonical URL or fallback to BaseUrlService
            $baseUrl = $seoBaseUrl !== '' ? rtrim($seoBaseUrl, '/') : BaseUrlService::getCurrentBaseUrl();

            $sitemap = $this->generateSitemapXml($baseUrl, $albums, $categories);
            
            // Save sitemap to public directory
            $sitemapPath = dirname(__DIR__, 3) . '/public/sitemap.xml';
            file_put_contents($sitemapPath, $sitemap);
            
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Sitemap generated successfully'];
            
        } catch (\Throwable $e) {
            Logger::error('SeoController::generateSitemap error', ['error' => $e->getMessage()], 'admin');
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error generating sitemap: ' . $e->getMessage()];
        }
        
        return $response->withHeader('Location', $this->redirect('/admin/seo'))->withStatus(302);
    }

    private function generateSitemapXml(string $baseUrl, array $albums, array $categories): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        // Homepage
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . htmlspecialchars($baseUrl . '/') . '</loc>' . "\n";
        $xml .= '    <priority>1.0</priority>' . "\n";
        $xml .= '    <changefreq>weekly</changefreq>' . "\n";
        $xml .= '  </url>' . "\n";
        
        // Galleries page
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . htmlspecialchars($baseUrl . '/galleries') . '</loc>' . "\n";
        $xml .= '    <priority>0.9</priority>' . "\n";
        $xml .= '    <changefreq>weekly</changefreq>' . "\n";
        $xml .= '  </url>' . "\n";
        
        // Categories
        foreach ($categories as $category) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($baseUrl . '/category/' . $category['slug']) . '</loc>' . "\n";
            if ($category['last_modified']) {
                $xml .= '    <lastmod>' . date('Y-m-d', strtotime($category['last_modified'])) . '</lastmod>' . "\n";
            }
            $xml .= '    <priority>0.8</priority>' . "\n";
            $xml .= '    <changefreq>monthly</changefreq>' . "\n";
            $xml .= '  </url>' . "\n";
        }
        
        // Albums
        foreach ($albums as $album) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($baseUrl . '/album/' . $album['slug']) . '</loc>' . "\n";
            if ($album['updated_at']) {
                $xml .= '    <lastmod>' . date('Y-m-d', strtotime($album['updated_at'])) . '</lastmod>' . "\n";
            }
            $xml .= '    <priority>0.7</priority>' . "\n";
            $xml .= '    <changefreq>monthly</changefreq>' . "\n";
            $xml .= '  </url>' . "\n";
        }
        
        $xml .= '</urlset>';
        return $xml;
    }
}