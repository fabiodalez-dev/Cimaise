<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class UsersController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;
        
        $pdo = $this->db->pdo();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        
        $stmt = $pdo->prepare('
            SELECT id, email, first_name, last_name, role, is_active, last_login, created_at 
            FROM users 
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset
        ');
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        $pages = (int)ceil(($total ?: 0) / $perPage);
        
        return $this->view->render($response, 'admin/users/index.twig', [
            'users' => $users,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/users/create.twig', [
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array)$request->getParsedBody();
        
        // Validate required fields
        $email = trim((string)($data['email'] ?? ''));
        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $role = (string)($data['role'] ?? 'user');
        $password = (string)($data['password'] ?? '');
        $confirmPassword = (string)($data['confirm_password'] ?? '');
        $isActive = isset($data['is_active']) ? 1 : 0;
        
        // Validation
        if (empty($email) || empty($firstName) || empty($lastName) || empty($password)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'All required fields must be filled'];
            return $response->withHeader('Location', $this->redirect('/admin/users/create'))->withStatus(302);
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid email'];
            return $response->withHeader('Location', $this->redirect('/admin/users/create'))->withStatus(302);
        }
        
        if (strlen($password) < 8) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Password must be at least 8 characters'];
            return $response->withHeader('Location', $this->redirect('/admin/users/create'))->withStatus(302);
        }
        
        if ($password !== $confirmPassword) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Passwords do not match'];
            return $response->withHeader('Location', $this->redirect('/admin/users/create'))->withStatus(302);
        }
        
        if (!in_array($role, ['admin', 'user'])) {
            $role = 'user';
        }
        
        // Check if email already exists
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Email already in use'];
            return $response->withHeader('Location', $this->redirect('/admin/users/create'))->withStatus(302);
        }
        
        // Create user
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        $stmt = $this->db->pdo()->prepare('
            INSERT INTO users (email, first_name, last_name, password_hash, role, is_active) 
            VALUES (:email, :first_name, :last_name, :password_hash, :role, :is_active)
        ');
        
        try {
            $stmt->execute([
                ':email' => $email,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':password_hash' => $passwordHash,
                ':role' => $role,
                ':is_active' => $isActive
            ]);
            
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'User created successfully'];
            return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
        } catch (\Throwable $e) {
            error_log('UsersController::store error: ' . $e->getMessage());
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'An error occurred while creating user. Please try again.'];
            return $response->withHeader('Location', $this->redirect('/admin/users/create'))->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'User not found'];
            return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
        }
        
        return $this->view->render($response, 'admin/users/edit.twig', [
            'user' => $user,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $data = (array)$request->getParsedBody();
        
        // Get current user data
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $currentUser = $stmt->fetch();
        
        if (!$currentUser) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'User not found'];
            return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
        }
        
        // Validate fields
        $email = trim((string)($data['email'] ?? ''));
        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $role = (string)($data['role'] ?? 'user');
        $isActive = isset($data['is_active']) ? 1 : 0;
        $password = (string)($data['password'] ?? '');
        $confirmPassword = (string)($data['confirm_password'] ?? '');
        
        // Validation
        if (empty($email) || empty($firstName) || empty($lastName)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Email, first name and last name are required'];
            return $response->withHeader('Location', $this->redirect('/admin/users/' . $id . '/edit'))->withStatus(302);
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid email'];
            return $response->withHeader('Location', $this->redirect('/admin/users/' . $id . '/edit'))->withStatus(302);
        }
        
        if (!empty($password)) {
            if (strlen($password) < 8) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Password must be at least 8 characters'];
                return $response->withHeader('Location', $this->redirect('/admin/users/' . $id . '/edit'))->withStatus(302);
            }
            
            if ($password !== $confirmPassword) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Passwords do not match'];
                return $response->withHeader('Location', $this->redirect('/admin/users/' . $id . '/edit'))->withStatus(302);
            }
        }
        
        if (!in_array($role, ['admin', 'user'])) {
            $role = 'user';
        }
        
        // Check email uniqueness (excluding current user)
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
        $stmt->execute([':email' => $email, ':id' => $id]);
        if ($stmt->fetch()) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Email already in use by another user'];
            return $response->withHeader('Location', $this->redirect('/admin/users/' . $id . '/edit'))->withStatus(302);
        }
        
        // Update user
        $now = $this->db->nowExpression();
        if (!empty($password)) {
            $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
            $stmt = $this->db->pdo()->prepare("
                UPDATE users
                SET email = :email, first_name = :first_name, last_name = :last_name,
                    password_hash = :password_hash, role = :role, is_active = :is_active,
                    updated_at = {$now}
                WHERE id = :id
            ");
            $params = [
                ':email' => $email,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':password_hash' => $passwordHash,
                ':role' => $role,
                ':is_active' => $isActive,
                ':id' => $id
            ];
        } else {
            $stmt = $this->db->pdo()->prepare("
                UPDATE users
                SET email = :email, first_name = :first_name, last_name = :last_name,
                    role = :role, is_active = :is_active, updated_at = {$now}
                WHERE id = :id
            ");
            $params = [
                ':email' => $email,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':role' => $role,
                ':is_active' => $isActive,
                ':id' => $id
            ];
        }
        
        try {
            $stmt->execute($params);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'User updated successfully'];
            return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
        } catch (\Throwable $e) {
            error_log('UsersController::update error: ' . $e->getMessage());
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'An error occurred while updating user. Please try again.'];
            return $response->withHeader('Location', $this->redirect('/admin/users/' . $id . '/edit'))->withStatus(302);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        
        // Prevent self-deletion and ensure at least one admin remains
        if ($id === $_SESSION['admin_id']) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'You cannot delete your own account'];
            return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
        }
        
        // Check if user exists and get role
        $stmt = $this->db->pdo()->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'User not found'];
            return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
        }
        
        // If deleting an admin, ensure at least one admin remains
        if ($user['role'] === 'admin') {
            $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
            $adminCount = (int)$stmt->fetchColumn();
            
            if ($adminCount <= 1) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'At least one active administrator must remain'];
                return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
            }
        }
        
        // Delete user
        $stmt = $this->db->pdo()->prepare('DELETE FROM users WHERE id = :id');
        try {
            $stmt->execute([':id' => $id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'User deleted'];
        } catch (\Throwable $e) {
            error_log('UsersController::delete error: ' . $e->getMessage());
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'An error occurred while deleting user. Please try again.'];
        }

        return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
    }

    public function toggleActive(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        
        // Prevent self-deactivation
        if ($id === $_SESSION['admin_id']) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'You cannot deactivate your own account'];
            return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
        }
        
        // Get current status
        $stmt = $this->db->pdo()->prepare('SELECT is_active, role FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'User not found'];
            return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
        }
        
        $newStatus = $user['is_active'] ? 0 : 1;
        
        // If deactivating an admin, ensure at least one admin remains active
        if ($user['role'] === 'admin' && $newStatus === 0) {
            $stmt = $this->db->pdo()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
            $adminCount = (int)$stmt->fetchColumn();
            
            if ($adminCount <= 1) {
                $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'At least one active administrator must remain'];
                return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
            }
        }
        
        // Toggle status
        $now = $this->db->nowExpression();
        $stmt = $this->db->pdo()->prepare("UPDATE users SET is_active = :status, updated_at = {$now} WHERE id = :id");
        try {
            $stmt->execute([':status' => $newStatus, ':id' => $id]);
            $statusText = $newStatus ? 'activated' : 'deactivated';
            $_SESSION['flash'][] = ['type' => 'success', 'message' => "User {$statusText}"];
        } catch (\Throwable $e) {
            error_log('UsersController::toggleActive error: ' . $e->getMessage());
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'An error occurred while updating user status. Please try again.'];
        }

        return $response->withHeader('Location', $this->redirect('/admin/users'))->withStatus(302);
    }
}