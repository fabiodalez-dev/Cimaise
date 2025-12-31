<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TemplatesController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $templates = (new \App\Services\TemplateService($this->db))->getGalleryTemplates();

        return $this->view->render($response, 'admin/templates/index.twig', [
            'templates' => $templates,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    // New template creation is disabled
    public function create(Request $request, Response $response): Response
    {
        $_SESSION['flash'][] = ['type' => 'warning', 'message' => trans('admin.flash.templates_disabled')];
        return $response->withHeader('Location', $this->redirect('/admin/templates'))->withStatus(302);
    }

    // Saving new templates is disabled
    public function store(Request $request, Response $response): Response
    {
        $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.operation_not_allowed')];
        return $response->withHeader('Location', $this->redirect('/admin/templates'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id >= 1000) {
            $_SESSION['flash'][] = ['type' => 'warning', 'message' => trans('admin.flash.templates_custom_edit')];
            return $response->withHeader('Location', $this->redirect('/admin/custom-templates'))->withStatus(302);
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $template = $stmt->fetch();

        if (!$template) {
            $response->getBody()->write(trans('admin.flash.template_not_found'));
            return $response->withStatus(404);
        }

        // Decode settings for form display
        $template['settings'] = json_decode($template['settings'] ?? '{}', true) ?: [];
        $template['libs'] = json_decode($template['libs'] ?? '[]', true) ?: [];

        return $this->view->render($response, 'admin/templates/edit.twig', [
            'item' => $template,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/templates/'.$id.'/edit'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));

        if ($name === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.name_required')];
            return $response->withHeader('Location', $this->redirect('/admin/templates/'.$id.'/edit'))->withStatus(302);
        }

        if ($slug === '') {
            $slug = \App\Support\Str::slug($name);
        } else {
            $slug = \App\Support\Str::slug($slug);
        }

        // Get current template slug to check for magazine-split
        $slugStmt = $this->db->pdo()->prepare('SELECT slug FROM templates WHERE id = :id');
        $slugStmt->execute([':id' => $id]);
        $templateSlug = $slugStmt->fetchColumn();
        $isMagazine = $templateSlug === 'magazine-split';

        // Unified settings structure
        // Layout types: 'grid', 'masonry', 'dense_grid', 'css_masonry', 'magazine'
        $layout = $data['layout'] ?? 'grid';

        // Normalize layout to unified types (for backward compatibility)
        if (!in_array($layout, ['grid', 'masonry', 'dense_grid', 'css_masonry', 'magazine'], true)) {
            // Map old layout types to new unified types
            if (in_array($layout, ['masonry_fit', 'masonry_portfolio'], true)) {
                $layout = 'masonry';
            } elseif ($layout === 'slideshow' || $layout === 'fullscreen') {
                $layout = 'grid';
            }
        }

        // Override layout for magazine-split template
        if ($isMagazine) {
            $layout = 'magazine';
        }

        $isMasonryPortfolio = $templateSlug === 'masonry-portfolio' || $slug === 'masonry-portfolio';
        $isCreativeLayout = $templateSlug === 'grid-ampia' || $slug === 'grid-ampia';
        $isGalleryWall = $templateSlug === 'gallery-wall-scroll' || $slug === 'gallery-wall-scroll';

        // Process responsive columns
        $columns = [
            'desktop' => max(1, min(6, (int)($data['columns_desktop'] ?? 3))),
            'tablet' => max(1, min(4, (int)($data['columns_tablet'] ?? 2))),
            'mobile' => max(1, min(2, (int)($data['columns_mobile'] ?? 1)))
        ];

        // Process gap settings
        $gap = [
            'horizontal' => max(0, min(100, (int)($data['gap_horizontal'] ?? 16))),
            'vertical' => max(0, min(100, (int)($data['gap_vertical'] ?? 16)))
        ];

        // Masonry Portfolio (template 2) settings override
        $masonryPortfolio = null;
        if ($isMasonryPortfolio) {
            $mpCols = [
                'desktop' => max(2, min(8, (int)($data['masonry_col_desktop'] ?? 4))),
                'tablet' => max(1, min(6, (int)($data['masonry_col_tablet'] ?? 3))),
                'mobile' => max(1, min(4, (int)($data['masonry_col_mobile'] ?? 1)))
            ];
            $mpGapH = max(0, min(40, (int)($data['masonry_gap_h'] ?? 16)));
            $mpGapV = max(0, min(40, (int)($data['masonry_gap_v'] ?? 16)));
            $mpLayoutMode = $data['masonry_layout_mode'] ?? 'fullwidth';
            if (!in_array($mpLayoutMode, ['fullwidth', 'boxed'], true)) {
                $mpLayoutMode = 'fullwidth';
            }
            $mpType = $data['masonry_type'] ?? 'balanced';
            if (!in_array($mpType, ['balanced', 'regular'], true)) {
                $mpType = 'balanced';
            }
            $masonryPortfolio = [
                'columns' => $mpCols,
                'gap_h' => $mpGapH,
                'gap_v' => $mpGapV,
                'layout_mode' => $mpLayoutMode,
                'type' => $mpType
            ];

            // Keep unified settings in sync for template switcher fallbacks
            $columns = [
                'desktop' => max(1, min(6, $mpCols['desktop'])),
                'tablet' => max(1, min(4, $mpCols['tablet'])),
                'mobile' => max(1, min(2, $mpCols['mobile']))
            ];
            $gap = [
                'horizontal' => $mpGapH,
                'vertical' => $mpGapV
            ];
        }

        // Process aspect ratio (only for grid layout)
        $aspectRatio = $data['aspect_ratio'] ?? '1:1';
        if (!in_array($aspectRatio, ['1:1', '4:3', '16:9', '3:2'], true)) {
            $aspectRatio = '1:1';
        }

        // Process style settings
        $style = [
            'rounded' => isset($data['style_rounded']),
            'shadow' => isset($data['style_shadow']),
            'hover_scale' => isset($data['style_hover_scale']) || !isset($data['style_hover_scale_submitted']),
            'hover_fade' => isset($data['style_hover_fade']) || !isset($data['style_hover_fade_submitted'])
        ];

        // Build unified settings
        $settings = [
            'layout' => $layout,
            'columns' => $columns,
            'gap' => $gap,
            'aspect_ratio' => $aspectRatio,
            'style' => $style,
            'photoswipe' => [
                'loop' => isset($data['photoswipe_loop']),
                'zoom' => isset($data['photoswipe_zoom']),
                'share' => isset($data['photoswipe_share']),
                'counter' => isset($data['photoswipe_counter']),
                'arrowKeys' => isset($data['photoswipe_arrowkeys']),
                'escKey' => isset($data['photoswipe_esckey']),
                'bgOpacity' => (float)($data['photoswipe_bg_opacity'] ?? 0.8),
                'spacing' => (float)($data['photoswipe_spacing'] ?? 0.12),
                'allowPanToNext' => isset($data['photoswipe_pan_to_next'])
            ]
        ];

        // Magazine-specific settings for Magazine Split template (id 3)
        if ($isMagazine) {
            $magDur1 = max(10, min(300, (int)($data['mag_duration_1'] ?? 60)));
            $magDur2 = max(10, min(300, (int)($data['mag_duration_2'] ?? 72)));
            $magDur3 = max(10, min(300, (int)($data['mag_duration_3'] ?? 84)));
            $magGap = max(0, min(80, (int)($data['mag_gap'] ?? 20)));
            $settings['magazine'] = [
                'durations' => [$magDur1, $magDur2, $magDur3],
                'gap' => $magGap,
            ];
        }

        if ($masonryPortfolio !== null) {
            $settings['masonry_portfolio'] = $masonryPortfolio;
        }

        // Creative Layout (template 6) settings
        if ($isCreativeLayout) {
            $creativeGap = max(0, min(40, (int)($data['creative_gap'] ?? 15)));
            $creativeHoverTooltip = isset($data['creative_hover_tooltip']);
            $settings['creative_layout'] = [
                'gap' => $creativeGap,
                'hover_tooltip' => $creativeHoverTooltip
            ];
        }

        if ($isGalleryWall) {
            $wallDesktopH = max(0.8, min(3.0, (float)($data['wall_desktop_h_ratio'] ?? 1.5)));
            $wallDesktopV = max(0.4, min(1.5, (float)($data['wall_desktop_v_ratio'] ?? 0.67)));
            $wallTabletH = max(0.8, min(3.0, (float)($data['wall_tablet_h_ratio'] ?? 1.3)));
            $wallTabletV = max(0.4, min(1.5, (float)($data['wall_tablet_v_ratio'] ?? 0.6)));
            $wallDivider = max(0, min(10, (int)($data['wall_divider'] ?? 2)));
            $wallMobileCols = max(1, min(4, (int)($data['wall_mobile_cols'] ?? 2)));
            $wallMobileGap = max(0, min(40, (int)($data['wall_mobile_gap'] ?? 8)));
            $wallMobileWideEvery = max(0, min(10, (int)($data['wall_mobile_wide_every'] ?? 5)));

            $settings['layout'] = 'gallery_wall';
            $settings['gallery_wall'] = [
                'desktop' => [
                    'horizontal_ratio' => $wallDesktopH,
                    'vertical_ratio' => $wallDesktopV,
                ],
                'tablet' => [
                    'horizontal_ratio' => $wallTabletH,
                    'vertical_ratio' => $wallTabletV,
                ],
                'divider' => $wallDivider,
                'mobile' => [
                    'columns' => $wallMobileCols,
                    'gap' => $wallMobileGap,
                    'wide_every' => $wallMobileWideEvery,
                ],
            ];
        }

        // Dense Grid specific settings
        if ($layout === 'dense_grid') {
            $settings['dense_grid'] = [
                'minCellDesktop' => max(150, min(400, (int)($data['dense_min_desktop'] ?? 250))),
                'minCellTablet' => max(100, min(300, (int)($data['dense_min_tablet'] ?? 150))),
                'rowHeight' => max(150, min(400, (int)($data['dense_row_height'] ?? 250))),
                'rowHeightTablet' => max(100, min(300, (int)($data['dense_row_height_tablet'] ?? 180))),
                'rowHeightMobile' => max(150, min(400, (int)($data['dense_row_height_mobile'] ?? 300))),
                'gap' => max(0, min(32, (int)($data['dense_gap'] ?? 8))),
                'maxWidth' => max(800, min(2400, (int)($data['dense_max_width'] ?? 1600))),
                'adaptiveSizing' => isset($data['dense_adaptive_sizing'])
            ];
        }

        // Masonry-specific settings (balanced vs regular algorithm)
        if ($layout === 'masonry' && !$isMasonryPortfolio) {
            $masonryType = $data['masonry_type'] ?? 'balanced';
            if (!in_array($masonryType, ['balanced', 'regular'], true)) {
                $masonryType = 'balanced';
            }
            $settings['masonry'] = [
                'type' => $masonryType
            ];
        }

        // Determine required libraries
        $libs = ['photoswipe'];
        if ($layout === 'masonry' && !$isMasonryPortfolio) {
            $libs[] = 'masonry-grid'; // New modern library
        }
        // Dense grid uses pure CSS Grid, no additional libraries needed

        $stmt = $this->db->pdo()->prepare('UPDATE templates SET name=:n, slug=:s, description=:d, settings=:settings, libs=:libs WHERE id=:id');
        try {
            $stmt->execute([
                ':n' => $name,
                ':s' => $slug,
                ':d' => $description,
                ':settings' => json_encode($settings),
                ':libs' => json_encode($libs),
                ':id' => $id
            ]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.template_updated')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic')];
        }
        return $response->withHeader('Location', $this->redirect('/admin/templates'))->withStatus(302);
    }

    // Deleting templates is disabled
    public function delete(Request $request, Response $response, array $args): Response
    {
        $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.operation_not_allowed')];
        return $response->withHeader('Location', $this->redirect('/admin/templates'))->withStatus(302);
    }
}
