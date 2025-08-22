<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController
{
    public function __construct(private Database $db, private Twig $view)
    {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/login.twig', [
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $csrf = (string)($data['csrf'] ?? '');

        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => 'Token CSRF non valido.',
                'csrf' => $_SESSION['csrf'] ?? ''
            ]);
        }

        if ($email === '' || $password === '') {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => 'Email e password sono richiesti.',
                'csrf' => $_SESSION['csrf'] ?? ''
            ]);
        }

        $stmt = $this->db->pdo()->prepare('SELECT id, password_hash FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => 'Credenziali non valide.',
                'csrf' => $_SESSION['csrf'] ?? ''
            ]);
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$user['id'];
        // rotate CSRF after login
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        return $response
            ->withHeader('Location', '/admin')
            ->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
        }
        session_destroy();
        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }
}
