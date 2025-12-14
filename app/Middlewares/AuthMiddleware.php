<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Support\CookieHelper;
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
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = $basePath === '/' ? '' : $basePath;

        // Remove /public from the path if present (since document root should be public/)
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7); // Remove '/public'
        }

        // Skip auth check for login/logout routes
        $path = $request->getUri()->getPath();
        if (in_array($path, ['/admin/login'])) {
            return $handler->handle($request);
        }

        // Allow logout for authenticated users only
        if ($path === '/admin/logout') {
            if (empty($_SESSION['admin_id'])) {
                $response = new \Slim\Psr7\Response(302);
                return $response->withHeader('Location', $basePath . '/admin/login');
            }
            return $handler->handle($request);
        }

        // Try auto-login with remember token if no session
        if (empty($_SESSION['admin_id'])) {
            $this->tryRememberLogin();
        }

        if (empty($_SESSION['admin_id'])) {
            $response = new \Slim\Psr7\Response(302);
            return $response->withHeader('Location', $basePath . '/admin/login');
        }

        // Verify user still exists and is active
        $stmt = $this->db->pdo()->prepare('SELECT id, email, role, is_active, first_name, last_name FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active'] || $user['role'] !== 'admin') {
            // User no longer exists, is inactive, or no longer admin - force logout
            $this->clearRememberToken((int)($_SESSION['admin_id'] ?? 0));
            session_destroy();
            $response = new \Slim\Psr7\Response(302);
            return $response->withHeader('Location', $basePath . '/admin/login');
        }

        // Update session with current user data
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['admin_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['admin_role'] = $user['role'];

        return $handler->handle($request);
    }

    /**
     * Try to auto-login using remember token cookie
     */
    private function tryRememberLogin(): void
    {
        // Check if remember_token cookie exists
        if (empty($_COOKIE['remember_token'])) {
            return;
        }

        $rawToken = $_COOKIE['remember_token'];

        // Hash the cookie token to compare with database
        $hashedToken = hash('sha256', $rawToken);

        // SECURITY: Fetch users with non-expired tokens, then use constant-time comparison
        // This prevents timing attacks that could leak token information via response times
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, email, role, is_active, first_name, last_name, remember_token, remember_token_expires_at
             FROM users
             WHERE remember_token IS NOT NULL
             AND remember_token_expires_at > :now
             AND is_active = 1
             AND role = :role
             LIMIT 100'
        );
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':role' => 'admin']);
        $users = $stmt->fetchAll();

        $matchedUser = null;
        foreach ($users as $user) {
            // Use constant-time comparison to prevent timing attacks
            if (hash_equals($user['remember_token'], $hashedToken)) {
                $matchedUser = $user;
                break;
            }
        }

        if (!$matchedUser) {
            // Invalid token - clear the cookie
            $this->clearRememberCookie();
            return;
        }

        // Valid token - create session
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$matchedUser['id'];
        $_SESSION['admin_email'] = $matchedUser['email'];
        $_SESSION['admin_name'] = trim($matchedUser['first_name'] . ' ' . $matchedUser['last_name']);
        $_SESSION['admin_role'] = $matchedUser['role'];

        // Rotate remember token for security (token rotation)
        $this->rotateRememberToken((int)$matchedUser['id']);

        // Generate new CSRF token
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    /**
     * Rotate remember token (generate new token after successful use)
     */
    private function rotateRememberToken(int $userId): void
    {
        // Generate new token
        $rawToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));

        // Update database
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET remember_token = :token, remember_token_expires_at = :expires WHERE id = :id'
        );
        $stmt->execute([
            ':token' => $hashedToken,
            ':expires' => $expiresAt,
            ':id' => $userId
        ]);

        // Set new cookie
        setcookie('remember_token', $rawToken, [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => !CookieHelper::allowInsecureCookies(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    /**
     * Clear remember token from database and cookie
     */
    private function clearRememberToken(int $userId): void
    {
        if ($userId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE users SET remember_token = NULL, remember_token_expires_at = NULL WHERE id = :id'
            );
            $stmt->execute([':id' => $userId]);
        }
        $this->clearRememberCookie();
    }

    /**
     * Clear remember token cookie
     */
    private function clearRememberCookie(): void
    {
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => !CookieHelper::allowInsecureCookies(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

