<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController
{
    public function __construct(private Twig $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        if (!isset($_SESSION['admin_id'])) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }
        return $this->view->render($response, 'admin/dashboard.twig', [
            'userId' => $_SESSION['admin_id']
        ]);
    }
}

