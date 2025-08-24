<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\RateLimitMiddleware;

return function (App $app, array $container) {

// Frontend pages
$app->get('/', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->home($request, $response);
});

$app->get('/album/{slug}', function (Request $request, Response $response, array $args) use ($container) {
    // Redirect to gallery with album param - this ensures consistent behavior
    $newUrl = '/gallery?album=' . urlencode($args['slug']);
    return $response->withHeader('Location', $newUrl)->withStatus(302);
});
// Unlock password-protected album
$app->post('/album/{slug}/unlock', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->unlockAlbum($request, $response, $args);
})->add(new RateLimitMiddleware(5, 600));

$app->get('/category/{slug}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->category($request, $response, $args);
});

$app->get('/tag/{slug}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->tag($request, $response, $args);
});

// About page with dynamic slug from settings
$settingsSvc = new \App\Services\SettingsService($container['db']);
$aboutSlug = (string)($settingsSvc->get('about.slug', 'about') ?? 'about');
if ($aboutSlug === '') { $aboutSlug = 'about'; }
$aboutPath = '/' . $aboutSlug;
$app->get($aboutPath, function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\PageController($container['db'], Twig::fromRequest($request));
    return $controller->about($request, $response);
});
// Backward compat: if slug changed, redirect /about to new path
if ($aboutSlug !== 'about') {
    $app->get('/about', function (Request $request, Response $response) use ($aboutPath) {
        return $response->withHeader('Location', $aboutPath)->withStatus(302);
    });
}
// Contact submit for About, dynamic path
$app->post($aboutPath . '/contact', function (Request $request, Response $response) use ($container) {
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

// (public API routes are defined near the bottom of this file)

// Admin redirect
$app->get('/admin-login', function (Request $request, Response $response) {
    return $response->withHeader('Location', '/admin/login')->withStatus(302);
});

$app->get('/admin/login', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AuthController($container['db'], Twig::fromRequest($request));
    return $controller->showLogin($request, $response);
});

$app->post('/admin/login', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AuthController($container['db'], Twig::fromRequest($request));
    return $controller->login($request, $response);
})->add(new RateLimitMiddleware(5, 600));

$app->get('/admin/logout', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AuthController($container['db'], Twig::fromRequest($request));
    return $controller->logout($request, $response);
});

$app->get('/admin', function (Request $request, Response $response) {
    $controller = new \App\Controllers\Admin\DashboardController(Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());

// Upload + API
$app->post('/admin/albums/{id}/upload', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\UploadController($container['db']);
    return $controller->uploadToAlbum($request, $response, $args);
})->add(new AuthMiddleware());
$app->get('/admin/api/tags', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\ApiController($container['db']);
    return $controller->tags($request, $response);
})->add(new AuthMiddleware());

$app->get('/admin/api/category/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\ApiController($container['db']);
    return $controller->category($request, $response, $args);
})->add(new AuthMiddleware());

// Images actions and tags update
$app->post('/admin/albums/{id}/cover/{imageId}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->setCover($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}/images/reorder', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->reorderImages($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}/images/{imageId}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->deleteImage($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}/images/bulk-delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->bulkDeleteImages($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}/images/{imageId}/update', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->updateImageMeta($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}/tags', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->updateTags($request, $response, $args);
})->add(new AuthMiddleware());

// Settings
$app->get('/admin/settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SettingsController($container['db'], Twig::fromRequest($request));
    return $controller->show($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/settings', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SettingsController($container['db'], Twig::fromRequest($request));
    return $controller->save($request, $response);
})->add(new AuthMiddleware());

$app->post('/admin/settings/generate-images', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\SettingsController($container['db'], Twig::fromRequest($request));
    return $controller->generateImages($request, $response);
})->add(new AuthMiddleware());

// Diagnostics
$app->get('/admin/diagnostics', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\DiagnosticsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());

// Commands
$app->get('/admin/commands', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CommandsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/commands/execute', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CommandsController($container['db'], Twig::fromRequest($request));
    return $controller->execute($request, $response);
})->add(new AuthMiddleware());

// Pages admin list
$app->get('/admin/pages', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\PagesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
// About page edit
$app->get('/admin/pages/about', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\PagesController($container['db'], Twig::fromRequest($request));
    return $controller->aboutForm($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/pages/about', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\PagesController($container['db'], Twig::fromRequest($request));
    return $controller->saveAbout($request, $response);
})->add(new AuthMiddleware());

// Albums CRUD
$app->get('/admin/albums', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/albums/reorder', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->reorderList($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/albums/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/albums', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/albums/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}/publish', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->publish($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/albums/{id}/unpublish', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\AlbumsController($container['db'], Twig::fromRequest($request));
    return $controller->unpublish($request, $response, $args);
})->add(new AuthMiddleware());

// Categories CRUD
$app->get('/admin/categories', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/categories/reorder', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->reorder($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/categories/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/categories', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/categories/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/categories/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/categories/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CategoriesController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());

// Tags CRUD
$app->get('/admin/tags', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/tags/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/tags', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/tags/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/tags/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/tags/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TagsController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());

// Cameras CRUD
$app->get('/admin/cameras', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/cameras/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/cameras', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/cameras/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/cameras/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/cameras/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\CamerasController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());

// Lenses CRUD
$app->get('/admin/lenses', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/lenses/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/lenses', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/lenses/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/lenses/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/lenses/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LensesController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());

// Films CRUD
$app->get('/admin/films', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/films/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/films', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/films/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/films/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/films/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\FilmsController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());

// Developers CRUD
$app->get('/admin/developers', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/developers/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/developers', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/developers/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/developers/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/developers/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\DevelopersController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());

// Labs CRUD
$app->get('/admin/labs', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/labs/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/labs', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/labs/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/labs/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/labs/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\LabsController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());

// Templates CRUD
$app->get('/admin/templates', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->index($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/templates/create', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->create($request, $response);
})->add(new AuthMiddleware());
$app->post('/admin/templates', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->store($request, $response);
})->add(new AuthMiddleware());
$app->get('/admin/templates/{id}/edit', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->edit($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/templates/{id}', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->update($request, $response, $args);
})->add(new AuthMiddleware());
$app->post('/admin/templates/{id}/delete', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Admin\TemplatesController($container['db'], Twig::fromRequest($request));
    return $controller->delete($request, $response, $args);
})->add(new AuthMiddleware());

// Frontend API (public)
$app->get('/api/albums', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Frontend\ApiController($container['db'], Twig::fromRequest($request));
    return $controller->albums($request, $response);
});

$app->get('/api/album/{id}/images', function (Request $request, Response $response, array $args) use ($container) {
    $controller = new \App\Controllers\Frontend\ApiController($container['db'], Twig::fromRequest($request));
    return $controller->albumImages($request, $response, $args);
});

}; // End routes function
