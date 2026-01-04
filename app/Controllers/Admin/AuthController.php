<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Services\VariantMaintenanceService;
use App\Support\CookieHelper;
use App\Support\Database;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController extends BaseController
{
    private const REMEMBER_TOKEN_DAYS = 30;

    public function __construct(
        private Database $db,
        private Twig $view,
        private SettingsService $settings
    )
    {
        parent::__construct();
    }

    public function showLogin(Request $request, Response $response): Response
    {
        // Redirect to dashboard if already logged in
        if (isset($_SESSION['admin_id'])) {
            return $response->withHeader('Location', $this->redirect('/admin'))->withStatus(302);
        }

        return $this->view->render($response, 'admin/login.twig', [
            'csrf' => $_SESSION['csrf'] ?? '',
            'admin_locale' => $this->getAdminLocale()
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $csrf = (string)($data['csrf'] ?? '');
        $rememberMe = !empty($data['remember_me']);
        $adminLocale = $this->getAdminLocale();

        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => trans('admin.flash.csrf_invalid'),
                'csrf' => $_SESSION['csrf'] ?? '',
                'admin_locale' => $adminLocale
            ]);
        }

        if ($email === '' || $password === '') {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => trans('admin.flash.email_password_required'),
                'csrf' => $_SESSION['csrf'] ?? '',
                'admin_locale' => $adminLocale
            ]);
        }

        $stmt = $this->db->pdo()->prepare('SELECT id, email, password_hash, role, is_active, first_name, last_name FROM users WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => trans('admin.flash.invalid_credentials'),
                'csrf' => $_SESSION['csrf'] ?? '',
                'admin_locale' => $adminLocale
            ]);
        }

        // Check if user is active
        if (!$user['is_active']) {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => trans('admin.flash.account_deactivated'),
                'csrf' => $_SESSION['csrf'] ?? '',
                'admin_locale' => $adminLocale
            ]);
        }

        // Check if user has admin role for backend access
        if ($user['role'] !== 'admin') {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => trans('admin.flash.access_denied_admin_only'),
                'csrf' => $_SESSION['csrf'] ?? '',
                'admin_locale' => $adminLocale
            ]);
        }

        // Update last login timestamp
        $now = $this->db->nowExpression();
        $updateStmt = $this->db->pdo()->prepare("UPDATE users SET last_login = {$now} WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$user['id'];
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['admin_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['admin_role'] = $user['role'];

        // Handle "Remember Me" functionality
        if ($rememberMe) {
            $this->setRememberToken((int)$user['id']);
        }

        // rotate CSRF after login
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        $this->scheduleDailyVariantMaintenance();

        return $response
            ->withHeader('Location', $this->redirect('/admin'))
            ->withStatus(302);
    }

    /**
     * Generate and set remember token for persistent login
     */
    private function setRememberToken(int $userId): void
    {
        try {
            // Generate secure random token (32 bytes = 64 hex chars)
            $rawToken = bin2hex(random_bytes(32));

            // Hash token before storing in database
            $hashedToken = hash('sha256', $rawToken);

            // Calculate expiration timestamp
            $expiresSeconds = self::REMEMBER_TOKEN_DAYS * 24 * 60 * 60;
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresSeconds);

            // Store hashed token in database
            $stmt = $this->db->pdo()->prepare(
                'UPDATE users SET remember_token = :token, remember_token_expires_at = :expires WHERE id = :id'
            );
            $stmt->execute([
                ':token' => $hashedToken,
                ':expires' => $expiresAt,
                ':id' => $userId
            ]);

            // Set cookie with raw token (not hashed)
            setcookie('remember_token', $rawToken, [
                'expires' => time() + $expiresSeconds,
                'path' => '/',
                'secure' => !CookieHelper::allowInsecureCookies(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            Logger::info('Remember token set for user', ['user_id' => $userId], 'auth');
        } catch (\Throwable $e) {
            Logger::error('Failed to set remember token', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ], 'auth');
            // Don't throw - login should still succeed even if remember token fails
        }
    }

    /**
     * Clear remember token from database and cookie
     */
    private function clearRememberToken(int $userId): void
    {
        // Clear from database
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET remember_token = NULL, remember_token_expires_at = NULL WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);

        // Clear cookie
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => !CookieHelper::allowInsecureCookies(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        Logger::info('Remember token cleared for user', ['user_id' => $userId], 'auth');
    }

    public function logout(Request $request, Response $response): Response
    {
        // SECURITY: Verify CSRF token for logout
        if ($request->getMethod() === 'POST') {
            $data = (array)($request->getParsedBody() ?? []);
            $csrf = (string)($data['csrf'] ?? '');

            if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
                return $response->withHeader('Location', $this->redirect('/admin'))->withStatus(302);
            }
        }

        // Clear remember token before destroying session
        if (!empty($_SESSION['admin_id'])) {
            $this->clearRememberToken((int)$_SESSION['admin_id']);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
        }
        session_destroy();
        return $response->withHeader('Location', $this->redirect('/admin/login'))->withStatus(302);
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        if (empty($_SESSION['admin_id'])) {
            return $response->withHeader('Location', $this->redirect('/admin/login'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $csrf = (string)($data['csrf'] ?? '');

        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if ($firstName === '' || $lastName === '' || $email === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.all_fields_required')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        // SECURITY: Validate names to prevent XSS
        if (strlen($firstName) > 50 || strlen($lastName) > 50) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.name_max_length')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if (!preg_match('/^[a-zA-Z\s\-\'\.À-ſ]+$/u', $firstName) || !preg_match('/^[a-zA-Z\s\-\'\.À-ſ]+$/u', $lastName)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.name_invalid_chars')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.email_invalid')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        // Check if email is already taken by another user
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
        $stmt->execute([':email' => $email, ':id' => $_SESSION['admin_id']]);
        if ($stmt->fetch()) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.email_already_used')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        try {
            $now = $this->db->nowExpression();
            $stmt = $this->db->pdo()->prepare(
                "UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, updated_at = {$now} WHERE id = :id"
            );
            $stmt->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':id' => $_SESSION['admin_id']
            ]);

            // Update session data
            $_SESSION['admin_name'] = trim($firstName . ' ' . $lastName);
            $_SESSION['admin_email'] = $email;

            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.profile_updated')];
        } catch (\Throwable $e) {
            Logger::error('AuthController::updateProfile error', ['error' => $e->getMessage()], 'admin');
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic')];
        }

        return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
    }

    public function changePassword(Request $request, Response $response): Response
    {
        if (empty($_SESSION['admin_id'])) {
            return $response->withHeader('Location', $this->redirect('/admin/login'))->withStatus(302);
        }

        $data = (array)$request->getParsedBody();
        $currentPassword = (string)($data['current_password'] ?? '');
        $newPassword = (string)($data['new_password'] ?? '');
        $confirmPassword = (string)($data['confirm_password'] ?? '');
        $csrf = (string)($data['csrf'] ?? '');

        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.all_fields_required')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if (strlen($newPassword) < 8) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.password_min_length')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.passwords_not_match')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        // Verify current password
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.current_password_incorrect')];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);
            $now = $this->db->nowExpression();
            $stmt = $this->db->pdo()->prepare(
                "UPDATE users SET password_hash = :password_hash, updated_at = {$now} WHERE id = :id"
            );
            $stmt->execute([
                ':password_hash' => $hashedPassword,
                ':id' => $_SESSION['admin_id']
            ]);

            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.password_changed')];
        } catch (\Throwable $e) {
            Logger::error('AuthController::changePassword error', ['error' => $e->getMessage()], 'admin');
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.error_generic')];
        }

        return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
    }

    private function scheduleDailyVariantMaintenance(): void
    {
        $db = $this->db;
        register_shutdown_function(function() use ($db) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                (new VariantMaintenanceService($db))->runDaily();
            } catch (\Throwable $e) {
                Logger::warning('Variant maintenance skipped', ['error' => $e->getMessage()], 'maintenance');
            }
        });
    }

    private function getAdminLocale(): string
    {
        try {
            return (string)($this->settings->get('admin.language', 'en') ?? 'en');
        } catch (\Throwable) {
            return 'en';
        }
    }
}
