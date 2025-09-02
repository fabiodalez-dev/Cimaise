<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\RateLimitMiddleware;

// Helper function to check if app is installed
function isAppInstalled($db): bool {
    if (!$db) return false;
    try {
        $stmt = $db->query('SELECT COUNT(*) as count FROM users WHERE role = "admin"');
        $result = $stmt->fetch();
        return $result && $result['count'] > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

return function (App $app, array $container) {

// Installer routes (only register when not installed)
if (!$container['db'] || !isAppInstalled($container['db'])) {
$app->get('/install', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->index($request, $response);
});

$app->get('/install/database', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->showDatabaseConfig($request, $response);
});

$app->post('/install/database', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->processDatabaseConfig($request, $response);
});

$app->get('/install/admin', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->showAdminConfig($request, $response);
});

$app->post('/install/admin', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->processAdminConfig($request, $response);
});

$app->get('/install/settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->showSettingsConfig($request, $response);
});

$app->post('/install/settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->processSettingsConfig($request, $response);
});

$app->get('/install/confirm', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->showConfirm($request, $response);
});

$app->post('/install/run', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->runInstall($request, $response);
});

// Post-install setup (site settings)
$app->get('/install/post-setup', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->showPostSetup($request, $response);
});
$app->post('/install/post-setup', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\InstallerController(Twig::fromRequest($request));
    return $controller->processPostSetup($request, $response);
});
}

// Frontend pages
$app->get('/', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->home($request, $response);
});

$app->get('/album/{slug}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->album($request, $response, $args);
});
// Unlock password-protected album
$app->post('/album/{slug}/unlock', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->unlockAlbum($request, $response, $args);
})->add(new RateLimitMiddleware(5, 600));

// Album template switcher API
$app->get('/api/album/{slug}/template', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->albumTemplate($request, $response, $args);
});

$app->get('/category/{slug}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->category($request, $response, $args);
});

$app->get('/tag/{slug}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->tag($request, $response, $args);
});

// About page with dynamic slug from settings
$app->get('/about', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->about($request, $response);
});

// Contact submit for About page
$app->post('/about/contact', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->aboutContact($request, $response);
})->add(new RateLimitMiddleware(5, 600));

// Image download route (validates album-level permissions)
$app->get('/download/image/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\DownloadController($container['db']);
    return $controller->downloadImage($request, $response, $args);
});

// Gallery preview page
$app->get('/gallery', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\GalleryController($container['db'], Twig::fromRequest($request));
    return $controller->gallery($request, $response);
});

// Gallery template switcher API
$app->get('/api/gallery/template', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\GalleryController($container['db'], Twig::fromRequest($request));
    return $controller->template($request, $response);
});

// Galleries page with advanced filtering
$app->get('/galleries', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\GalleriesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
});

// Galleries filter API
$app->get('/galleries/filter', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\GalleriesController($container['db'], Twig::fromRequest($request));
    return $controller->filter($request, $response);
});

// (public API routes are defined near the bottom of this file)

// Admin redirects
$app->get('/login', function (Request $request, Response $response) {
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    $basePath = $basePath === '/' ? '' : $basePath;
    
    // Remove /public from the path if present (since document root should be public/)
    if (str_ends_with($basePath, '/public')) {
        $basePath = substr($basePath, 0, -7); // Remove '/public'
    }
    
    return $response->withHeader('Location', $basePath . '/admin/login')->withStatus(302);
});

$app->get('/admin-login', function (Request $request, Response $response) {
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    $basePath = $basePath === '/' ? '' : $basePath;
    
    // Remove /public from the path if present (since document root should be public/)
    if (str_ends_with($basePath, '/public')) {
        $basePath = substr($basePath, 0, -7); // Remove '/public'
    }
    
    return $response->withHeader('Location', $basePath . '/admin/login')->withStatus(302);
});

$app->get('/admin/login', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AuthController($container['db'], Twig::fromRequest($request));
    return $controller->showLogin($request, $response);
});

$app->post('/admin/login', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AuthController($container['db'], Twig::fromRequest($request));
    return $controller->login($request, $response);
})->add(new RateLimitMiddleware(5, 600));

$app->post('/admin/logout', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AuthController($container['db'], Twig::fromRequest($request));
    return $controller->logout($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->post('/admin/profile/update', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AuthController($container['db'], Twig::fromRequest($request));
    return $controller->updateProfile($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->post('/admin/profile/password', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AuthController($container['db'], Twig::fromRequest($request));
    return $controller->changePassword($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->get('/admin', function (Request $request, Response $response) {
    $controller = new \App\Controllers\Admin\DashboardController(Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Upload + API
$app->post('/admin/albums/{id}/upload', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\UploadController($container['db']);
    return $controller->uploadToAlbum($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Settings: upload site logo
$app->post('/admin/settings/logo-upload', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\UploadController($container['db']);
    return $controller->uploadSiteLogo($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/api/tags', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\ApiController($container['db']);
    return $controller->tags($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->get('/admin/api/category/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\ApiController($container['db']);
    return $controller->category($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Images actions and tags update
$app->post('/admin/albums/{id}/cover/{imageId}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->setCover($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/images/reorder', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->reorderImages($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/images/{imageId}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->deleteImage($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/images/bulk-delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->bulkDeleteImages($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/images/attach', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->attachExisting($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/images/{imageId}/update', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->updateImageMeta($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/tags', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->updateTags($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Settings
$app->get('/admin/settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SettingsController($container['db'], Twig::fromRequest($request));
    return $controller->show($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SettingsController($container['db'], Twig::fromRequest($request));
    return $controller->save($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Filter Settings
$app->get('/admin/filter-settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilterSettingsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/filter-settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilterSettingsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/filter-settings/reset', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilterSettingsController($container['db'], Twig::fromRequest($request));
    return $controller->reset($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/filter-settings/preview', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilterSettingsController($container['db'], Twig::fromRequest($request));
    return $controller->preview($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->post('/admin/settings/generate-images', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SettingsController($container['db'], Twig::fromRequest($request));
    return $controller->generateImages($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// SEO Settings
$app->get('/admin/seo', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SeoController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/seo', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SeoController($container['db'], Twig::fromRequest($request));
    return $controller->save($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/seo/sitemap', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SeoController($container['db'], Twig::fromRequest($request));
    return $controller->generateSitemap($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Social Settings
$app->get('/admin/social', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SocialController($container['db'], Twig::fromRequest($request));
    return $controller->show($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/social', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SocialController($container['db'], Twig::fromRequest($request));
    return $controller->save($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Diagnostics
$app->get('/admin/diagnostics', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\DiagnosticsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Commands
$app->get('/admin/commands', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CommandsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/commands/execute', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CommandsController($container['db'], Twig::fromRequest($request));
    return $controller->execute($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Locations CRUD
$app->get('/admin/locations', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LocationsController(new \App\Repositories\LocationRepository($container['db']), Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/locations/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LocationsController(new \App\Repositories\LocationRepository($container['db']), Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/locations', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LocationsController(new \App\Repositories\LocationRepository($container['db']), Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/locations/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LocationsController(new \App\Repositories\LocationRepository($container['db']), Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/locations/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LocationsController(new \App\Repositories\LocationRepository($container['db']), Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/locations/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LocationsController(new \App\Repositories\LocationRepository($container['db']), Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Pages admin list
$app->get('/admin/pages', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\PagesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
// About page edit
$app->get('/admin/pages/about', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\PagesController($container['db'], Twig::fromRequest($request));
    return $controller->aboutForm($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/pages/about', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\PagesController($container['db'], Twig::fromRequest($request));
    return $controller->saveAbout($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
// Galleries page edit
$app->get('/admin/pages/galleries', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\PagesController($container['db'], Twig::fromRequest($request));
    return $controller->galleriesForm($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/pages/galleries', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\PagesController($container['db'], Twig::fromRequest($request));
    return $controller->saveGalleries($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Albums CRUD
$app->get('/admin/albums', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/reorder', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->reorderList($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/albums/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/albums/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/publish', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->publish($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/albums/{id}/unpublish', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->unpublish($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Categories CRUD
$app->get('/admin/categories', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/categories/reorder', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->reorder($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/categories/reorder-wordpress', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->reorderWordPress($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/categories/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/categories', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/categories/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/categories/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->put('/admin/categories/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/categories/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Tags CRUD
$app->get('/admin/tags', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/tags/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/tags', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/tags/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/tags/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/tags/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Cameras CRUD
$app->get('/admin/cameras', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/cameras/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/cameras', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/cameras/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/cameras/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/cameras/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Lenses CRUD
$app->get('/admin/lenses', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/lenses/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/lenses', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/lenses/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/lenses/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/lenses/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Films CRUD
$app->get('/admin/films', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/films/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/films', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/films/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/films/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/films/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Developers CRUD
$app->get('/admin/developers', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/developers/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/developers', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/developers/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/developers/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/developers/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Labs CRUD
$app->get('/admin/labs', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/labs/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/labs', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/labs/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/labs/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/labs/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Templates CRUD
$app->get('/admin/templates', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/templates/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/templates', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/templates/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/templates/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/templates/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Users CRUD
$app->get('/admin/users', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\UsersController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/users/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\UsersController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/users', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\UsersController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->get('/admin/users/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\UsersController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/users/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\UsersController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/users/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\UsersController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/users/{id}/toggle', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\UsersController($container['db'], Twig::fromRequest($request));
    return $controller->toggleActive($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Analytics routes
$app->get('/admin/analytics', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->get('/admin/analytics/realtime', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->realtime($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->get('/admin/analytics/settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->settings($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->post('/admin/analytics/settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->settings($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->get('/admin/analytics/albums', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->albums($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->get('/admin/analytics/export', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->export($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->post('/admin/analytics/cleanup', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->cleanup($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Analytics API endpoints
$app->get('/api/admin/analytics/charts', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->apiChartsData($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

$app->get('/api/admin/analytics/realtime', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->apiRealtime($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

// Public analytics tracking endpoint (no auth required)
$app->post('/api/analytics/track', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AnalyticsController($container['db'], Twig::fromRequest($request));
    return $controller->track($request, $response);
});

// Lightweight health check to verify routing in subdirectories
$app->get('/api/analytics/ping', function (Request $request, Response $response) {
    return $response->withStatus(204);
});

// Frontend API (public)
$app->get('/api/albums', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\ApiController($container['db'], Twig::fromRequest($request));
    return $controller->albums($request, $response);
});

$app->get('/api/album/{id}/images', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\ApiController($container['db'], Twig::fromRequest($request));
    return $controller->albumImages($request, $response, $args);
});

// Media Library
$app->get('/admin/media', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\MediaController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/media/images/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\MediaController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });
$app->post('/admin/media/images/{id}/update', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\MediaController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add($container['db'] ? new AuthMiddleware($container['db']) : function($request, $handler) { return $handler->handle($request); });

}; // End routes function
