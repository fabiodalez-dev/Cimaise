<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TestController
{
    public function __construct(private Database $db, private Twig $view)
    {
    }

    public function gallery(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $templateId = (int)($params['template'] ?? 1);
        
        // Get template settings
        try {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM templates WHERE id = :id');
            $stmt->execute([':id' => $templateId]);
            $template = $stmt->fetch();
        } catch (\Throwable $e) {
            $template = null;
        }
        
        // Default template if none found or templates table doesn't exist
        if (!$template) {
            $template = [
                'name' => 'Default Grid',
                'settings' => '{"layout":"grid","columns":{"desktop":3,"tablet":2,"mobile":1},"masonry":false,"photoswipe":{"loop":true,"zoom":true,"counter":true,"arrowKeys":true,"escKey":true,"bgOpacity":0.8,"spacing":0.12}}'
            ];
        }
        
        $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];
        
        // Generate test gallery metadata
        $galleryMeta = [
            'title' => 'Photography Portfolio Test Gallery',
            'category' => [
                'name' => 'Nature & Landscapes',
                'slug' => 'nature-landscapes'
            ],
            'excerpt' => 'A curated collection of test images showcasing different photographic styles and compositions. This gallery demonstrates the responsive template system with various aspect ratios and layout configurations.',
            'body' => '<p>This test gallery features a diverse selection of images designed to demonstrate the flexibility and responsiveness of our template system. Each image has been carefully selected to showcase different aspect ratios, compositions, and photographic styles.</p><p>The gallery supports multiple layout options including traditional grid views, dynamic masonry layouts, immersive slideshow presentations, and fullscreen experiences. Each template can be configured with responsive column settings for optimal viewing across desktop, tablet, and mobile devices.</p>',
            'shoot_date' => '2024-01-15',
            'location' => 'Various Locations',
            'photographer' => 'Test Photographer',
            'tags' => [
                ['name' => 'Test Gallery', 'slug' => 'test-gallery'],
                ['name' => 'Responsive Design', 'slug' => 'responsive-design'],
                ['name' => 'Template Demo', 'slug' => 'template-demo'],
                ['name' => 'Photography', 'slug' => 'photography'],
                ['name' => 'Portfolio', 'slug' => 'portfolio']
            ],
            'equipment' => [
                'cameras' => ['Canon EOS R5', 'Sony A7 IV', 'Fujifilm X-T4'],
                'lenses' => ['24-70mm f/2.8', '70-200mm f/4', '16-35mm f/2.8'],
                'film' => ['Kodak Portra 400', 'Fuji Velvia 50', 'Ilford HP5+']
            ]
        ];
        
        // Generate test images with various aspect ratios and metadata
        $testImages = [
            [
                'id' => 1,
                'url' => 'https://picsum.photos/800/600?random=1',
                'alt' => 'Mountain landscape at golden hour',
                'width' => 800,
                'height' => 600,
                'caption' => 'Golden hour light illuminating mountain peaks',
                'camera' => 'Canon EOS R5',
                'lens' => '24-70mm f/2.8',
                'settings' => 'f/8, 1/125s, ISO 100'
            ],
            [
                'id' => 2,
                'url' => 'https://picsum.photos/600/800?random=2',
                'alt' => 'Portrait of person in natural light',
                'width' => 600,
                'height' => 800,
                'caption' => 'Natural portrait using available light',
                'camera' => 'Sony A7 IV',
                'lens' => '85mm f/1.8',
                'settings' => 'f/2.8, 1/200s, ISO 200'
            ],
            [
                'id' => 3,
                'url' => 'https://picsum.photos/800/800?random=3',
                'alt' => 'Abstract architectural detail',
                'width' => 800,
                'height' => 800,
                'caption' => 'Modern architectural patterns and shadows',
                'camera' => 'Fujifilm X-T4',
                'lens' => '16-35mm f/2.8',
                'settings' => 'f/11, 1/60s, ISO 160'
            ],
            [
                'id' => 4,
                'url' => 'https://picsum.photos/1200/400?random=4',
                'alt' => 'Panoramic coastal view',
                'width' => 1200,
                'height' => 400,
                'caption' => 'Wide panoramic view of coastal landscape',
                'camera' => 'Canon EOS R5',
                'lens' => '16-35mm f/2.8',
                'settings' => 'f/16, 1/30s, ISO 50'
            ],
            [
                'id' => 5,
                'url' => 'https://picsum.photos/800/1200?random=5',
                'alt' => 'Vertical forest composition',
                'width' => 800,
                'height' => 1200,
                'caption' => 'Tall trees creating natural leading lines',
                'camera' => 'Sony A7 IV',
                'lens' => '24-70mm f/2.8',
                'settings' => 'f/5.6, 1/80s, ISO 320'
            ],
            [
                'id' => 6,
                'url' => 'https://picsum.photos/600/400?random=6',
                'alt' => 'Street photography scene',
                'width' => 600,
                'height' => 400,
                'caption' => 'Candid moment captured in urban environment',
                'camera' => 'Fujifilm X-T4',
                'lens' => '35mm f/2',
                'settings' => 'f/4, 1/125s, ISO 800'
            ],
            [
                'id' => 7,
                'url' => 'https://picsum.photos/900/600?random=7',
                'alt' => 'Macro detail of natural texture',
                'width' => 900,
                'height' => 600,
                'caption' => 'Close-up detail revealing intricate textures',
                'camera' => 'Canon EOS R5',
                'lens' => '100mm f/2.8 Macro',
                'settings' => 'f/8, 1/160s, ISO 200'
            ],
            [
                'id' => 8,
                'url' => 'https://picsum.photos/700/1000?random=8',
                'alt' => 'Dramatic sky composition',
                'width' => 700,
                'height' => 1000,
                'caption' => 'Storm clouds creating dramatic atmosphere',
                'camera' => 'Sony A7 IV',
                'lens' => '70-200mm f/4',
                'settings' => 'f/8, 1/250s, ISO 100'
            ],
            [
                'id' => 9,
                'url' => 'https://picsum.photos/800/500?random=9',
                'alt' => 'Minimalist composition',
                'width' => 800,
                'height' => 500,
                'caption' => 'Simple, clean composition with negative space',
                'camera' => 'Fujifilm X-T4',
                'lens' => '56mm f/1.2',
                'settings' => 'f/4, 1/200s, ISO 160'
            ],
            [
                'id' => 10,
                'url' => 'https://picsum.photos/600/900?random=10',
                'alt' => 'Urban architecture study',
                'width' => 600,
                'height' => 900,
                'caption' => 'Geometric patterns in modern architecture',
                'camera' => 'Canon EOS R5',
                'lens' => '24-70mm f/2.8',
                'settings' => 'f/11, 1/125s, ISO 100'
            ],
            [
                'id' => 11,
                'url' => 'https://picsum.photos/1000/600?random=11',
                'alt' => 'Landscape with foreground interest',
                'width' => 1000,
                'height' => 600,
                'caption' => 'Layered landscape composition with foreground elements',
                'camera' => 'Sony A7 IV',
                'lens' => '16-35mm f/2.8',
                'settings' => 'f/11, 1/60s, ISO 100'
            ],
            [
                'id' => 12,
                'url' => 'https://picsum.photos/500/700?random=12',
                'alt' => 'Abstract light and shadow play',
                'width' => 500,
                'height' => 700,
                'caption' => 'Interplay of light and shadow creating abstract forms',
                'camera' => 'Fujifilm X-T4',
                'lens' => '35mm f/2',
                'settings' => 'f/5.6, 1/100s, ISO 400'
            ]
        ];
        
        // Get available templates for template selector
        try {
            $stmt = $this->db->pdo()->query('SELECT id, name, settings FROM templates ORDER BY name ASC');
            $availableTemplates = $stmt->fetchAll() ?: [];
            
            foreach ($availableTemplates as &$tpl) {
                $tpl['settings'] = json_decode($tpl['settings'] ?? '{}', true) ?: [];
            }
        } catch (\Throwable $e) {
            $availableTemplates = [];
        }
        
        return $this->view->render($response, 'frontend/test-gallery.twig', [
            'album' => $galleryMeta,
            'images' => $testImages,
            'template_name' => $template['name'],
            'template_settings' => $templateSettings,
            'available_templates' => $availableTemplates,
            'current_template_id' => $templateId,
            'page_title' => $galleryMeta['title'] . ' - ' . $template['name'],
            'meta_description' => $galleryMeta['excerpt']
        ]);
    }
}