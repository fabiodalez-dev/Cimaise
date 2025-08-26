<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
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
        $email = strtolower(trim((string)($data['email'] ?? '')));
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

        $stmt = $this->db->pdo()->prepare('SELECT id, email, password_hash, role, is_active, first_name, last_name FROM users WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => 'Credenziali non valide.',
                'csrf' => $_SESSION['csrf'] ?? ''
            ]);
        }
        
        // Check if user is active
        if (!$user['is_active']) {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => 'Account disattivato. Contatta un amministratore.',
                'csrf' => $_SESSION['csrf'] ?? ''
            ]);
        }
        
        // Check if user has admin role for backend access
        if ($user['role'] !== 'admin') {
            return $this->view->render($response, 'admin/login.twig', [
                'error' => 'Accesso negato. Solo gli amministratori possono accedere al backend.',
                'csrf' => $_SESSION['csrf'] ?? ''
            ]);
        }

        // Update last login timestamp
        $updateStmt = $this->db->pdo()->prepare('UPDATE users SET last_login = datetime("now") WHERE id = :id');
        $updateStmt->execute([':id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$user['id'];
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['admin_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['admin_role'] = $user['role'];
        
        // rotate CSRF after login
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        return $response
            ->withHeader('Location', $this->redirect('/admin'))
            ->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        // SECURITY: Verify CSRF token for logout
        if ($request->getMethod() === 'POST') {
            $data = (array)($request->getParsedBody() ?? []);
            $csrf = (string)($data['csrf'] ?? '');
            
            if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Token CSRF non valido.'];
                return $response->withHeader('Location', $this->redirect('/admin'))->withStatus(302);
            }
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
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Token CSRF non valido.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if ($firstName === '' || $lastName === '' || $email === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Tutti i campi sono obbligatori.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }
        
        // SECURITY: Validate names to prevent XSS
        if (strlen($firstName) > 50 || strlen($lastName) > 50) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Nome e cognome devono essere massimo 50 caratteri.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }
        
        if (!preg_match('/^[a-zA-Z\s\-\'\.À-ſ]+$/u', $firstName) || !preg_match('/^[a-zA-Z\s\-\'\.À-ſ]+$/u', $lastName)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Nome e cognome possono contenere solo lettere, spazi, apostrofi e trattini.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Email non valida.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        // Check if email is already taken by another user
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
        $stmt->execute([':email' => $email, ':id' => $_SESSION['admin_id']]);
        if ($stmt->fetch()) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Questa email è già utilizzata da un altro utente.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, updated_at = datetime("now") WHERE id = :id'
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

            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Profilo aggiornato con successo.'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore durante l\'aggiornamento del profilo: ' . $e->getMessage()];
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
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Token CSRF non valido.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Tutti i campi sono obbligatori.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if (strlen($newPassword) < 8) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'La nuova password deve essere di almeno 8 caratteri.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Le password non corrispondono.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        // Verify current password
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Password attuale non corretta.'];
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
        }

        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->pdo()->prepare(
                'UPDATE users SET password_hash = :password_hash, updated_at = datetime("now") WHERE id = :id'
            );
            $stmt->execute([
                ':password_hash' => $hashedPassword,
                ':id' => $_SESSION['admin_id']
            ]);

            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Password cambiata con successo.'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore durante il cambio password: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? $this->redirect('/admin'))->withStatus(302);
    }
}
