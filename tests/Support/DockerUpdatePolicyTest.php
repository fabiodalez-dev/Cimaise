<?php
declare(strict_types=1);

use App\Controllers\Admin\UpdateController;
use App\Support\Database;
use App\Support\DockerEnvironment;
use App\Support\Updater;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class DockerUpdatePolicyTest extends TestCase
{
    public function testDockerBlocksBothInstallationEntryPoints(): void
    {
        $updater = new Updater(new Database(database: ':memory:', isSqlite: true), dockerDeployment: true);
        $before = file_get_contents(dirname(__DIR__, 2) . '/version.json');
        self::assertSame(DockerEnvironment::UPDATE_MESSAGE, $updater->performUpdate('99.0.0')['error']);
        self::assertSame(DockerEnvironment::UPDATE_MESSAGE, $updater->installUpdate('/nonexistent-package', '99.0.0')['error']);
        self::assertSame($before, file_get_contents(dirname(__DIR__, 2) . '/version.json'));
    }

    public function testDirectApiRequestCannotBypassDockerPolicy(): void
    {
        $previousEnv = getenv('CIMAISE_DOCKER');
        $session = $_SESSION ?? [];
        try {
            putenv('CIMAISE_DOCKER=1');
            self::assertTrue(DockerEnvironment::detected());
            $_SESSION = ['admin_role' => 'admin', 'csrf' => 'docker-update-test'];
            $controller = new UpdateController(new Database(database: ':memory:', isSqlite: true), Twig::create(dirname(__DIR__, 2) . '/app/Views'));
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/updates/perform')
                ->withParsedBody(['csrf' => 'docker-update-test', 'version' => '99.0.0']);
            $response = $controller->performUpdate($request, new Response());
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('docker_update_required', json_decode((string) $response->getBody(), true)['code']);
            self::assertSame(403, $controller->performUpdate($request->withParsedBody(['csrf' => 'wrong']), new Response())->getStatusCode());
        } finally {
            putenv($previousEnv === false ? 'CIMAISE_DOCKER' : 'CIMAISE_DOCKER=' . $previousEnv);
            $_SESSION = $session;
        }
    }

    public function testDockerPageShowsGenericInstructionsWithoutInstallButtons(): void
    {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'admin/_layout.twig' => '{% block content %}{% endblock %}',
            'admin/updates.twig' => file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/updates.twig'),
        ]));
        $twig->addFunction(new \Twig\TwigFunction('trans', static fn (string $key, mixed ...$args) => $key));
        $twig->addFunction(new \Twig\TwigFunction('csp_nonce', static fn () => 'test'));
        $twig->addFilter(new \Twig\TwigFilter('safe_html', static fn (string $html) => $html));
        $context = [
            'updateInfo' => ['current' => '1.0.0', 'latest' => '2.0.0', 'available' => true, 'package_asset' => true, 'error' => null],
            'requirements' => ['met' => true, 'requirements' => []],
        ];
        $docker = $twig->render('admin/updates.twig', $context + ['dockerDeployment' => true]);
        self::assertStringContainsString('id="docker-update-instructions"', $docker);
        self::assertStringContainsString('docker compose pull', $docker);
        self::assertStringNotContainsString('data-action="start-update"', $docker);
        self::assertStringNotContainsString('Unraid', $docker);
        self::assertStringContainsString('data-action="create-backup"', $docker);
        $normal = $twig->render('admin/updates.twig', $context + ['dockerDeployment' => false]);
        self::assertStringContainsString('data-action="start-update"', $normal);
        self::assertStringNotContainsString('id="docker-update-instructions"', $normal);
    }
}
