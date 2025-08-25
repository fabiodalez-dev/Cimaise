<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private Database $db)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        // Skip auth check for login/logout routes
        $path = $request->getUri()->getPath();
        if (in_array($path, ['/admin/login'])) {
            return $handler->handle($request);
        }
        
        // Allow logout for authenticated users only
        if ($path === '/admin/logout') {
            if (empty($_SESSION['admin_id'])) {
                $response = new \Slim\Psr7\Response(302);
                return $response->withHeader('Location', '/admin/login');
            }
            return $handler->handle($request);
        }
        
        if (empty($_SESSION['admin_id'])) {
            $response = new \Slim\Psr7\Response(302);
            return $response->withHeader('Location', '/admin/login');
        }
        
        // Verify user still exists and is active
        $stmt = $this->db->pdo()->prepare('SELECT id, email, role, is_active, first_name, last_name FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $user = $stmt->fetch();
        
        if (!$user || !$user['is_active'] || $user['role'] !== 'admin') {
            // User no longer exists, is inactive, or no longer admin - force logout
            session_destroy();
            $response = new \Slim\Psr7\Response(302);
            return $response->withHeader('Location', '/admin/login');
        }
        
        // Update session with current user data
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['admin_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['admin_role'] = $user['role'];
        
        return $handler->handle($request);
    }
}

