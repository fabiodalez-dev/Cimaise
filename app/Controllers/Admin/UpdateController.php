<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Support\Updater;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class UpdateController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    /**
     * Display the update management page
     */
    public function index(Request $request, Response $response): Response
    {
        // Admin-only access
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Access denied'];
            return $response->withHeader('Location', $this->redirect('/admin'))->withStatus(302);
        }

        $updater = new Updater($this->db);

        // Check for updates
        $updateInfo = $updater->checkForUpdates();
        $requirements = $updater->checkRequirements();
        $history = $updater->getUpdateHistory();
        $backups = $updater->getBackupList();
        $changelog = [];

        if ($updateInfo['available'] && $updateInfo['release']) {
            $changelog = $updater->getChangelog($updateInfo['current']);
        }

        // GitHub token state (the value itself is never sent to the view).
        $settings = new \App\Services\SettingsService($this->db);
        $storedToken = (string) ($settings->get('updater.github_token', '') ?? '');
        $tokenViaEnv = (string) (getenv('UPDATER_GITHUB_TOKEN') ?: '') !== '';

        return $this->view->render($response, 'admin/updates.twig', [
            'updateInfo' => $updateInfo,
            'requirements' => $requirements,
            'history' => $history,
            'backups' => $backups,
            'changelog' => $changelog,
            'csrf' => $_SESSION['csrf'] ?? '',
            'tokenConfigured' => $storedToken !== '',
            'tokenViaEnv' => $tokenViaEnv,
            'secretBoxAvailable' => \App\Support\SecretBox::isAvailable(),
        ]);
    }

    /**
     * API: Check for updates
     */
    public function checkUpdates(Request $request, Response $response): Response
    {
        // Admin-only access
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        $updater = new Updater($this->db);
        $updateInfo = $updater->checkForUpdates();

        return $this->jsonResponse($response, $updateInfo);
    }

    /**
     * API: Perform the update
     */
    public function performUpdate(Request $request, Response $response): Response
    {
        // Reject non-POST requests (defence-in-depth against proxy/method tampering)
        if ($request->getMethod() !== 'POST') {
            return $this->jsonResponse($response, ['error' => 'method_not_allowed'], 405);
        }

        // Admin-only access
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf'] ?? $data['csrf_token'] ?? '';

        if (empty($csrfToken) || !hash_equals($_SESSION['csrf'] ?? '', $csrfToken)) {
            return $this->jsonResponse($response, ['error' => 'Invalid CSRF token'], 403);
        }

        $targetVersion = $data['version'] ?? '';

        if (empty($targetVersion)) {
            return $this->jsonResponse($response, ['error' => 'Version not specified'], 400);
        }

        $updater = new Updater($this->db);

        // Check requirements first
        $requirements = $updater->checkRequirements();
        if (!$requirements['met']) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'System requirements not met',
                'requirements' => $requirements['requirements']
            ], 400);
        }

        // Perform the update
        $result = $updater->performUpdate($targetVersion);

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => sprintf('Update to version %s completed', $targetVersion),
                'backup_path' => $result['backup_path']
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error']
        ], 500);
    }

    /**
     * API: Create backup only
     */
    public function createBackup(Request $request, Response $response): Response
    {
        // Admin-only access
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf'] ?? $data['csrf_token'] ?? '';

        if (empty($csrfToken) || !hash_equals($_SESSION['csrf'] ?? '', $csrfToken)) {
            return $this->jsonResponse($response, ['error' => 'Invalid CSRF token'], 403);
        }

        $updater = new Updater($this->db);
        $result = $updater->createBackup();

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Backup created successfully',
                'path' => $result['path']
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error']
        ], 500);
    }

    /**
     * API: Get update history
     */
    public function getHistory(Request $request, Response $response): Response
    {
        // Admin-only access
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        $updater = new Updater($this->db);
        $history = $updater->getUpdateHistory();

        return $this->jsonResponse($response, ['history' => $history]);
    }

    /**
     * API: Check if update is available (for header notification)
     */
    public function checkAvailable(Request $request, Response $response): Response
    {
        // Any logged-in admin can check
        $userRole = $_SESSION['admin_role'] ?? '';
        if ($userRole !== 'admin') {
            return $this->jsonResponse($response, ['available' => false]);
        }

        $updater = new Updater($this->db);
        $updateInfo = $updater->checkForUpdates();

        return $this->jsonResponse($response, [
            'available' => $updateInfo['available'],
            'current' => $updateInfo['current'],
            'latest' => $updateInfo['latest'],
            // false = release published without the installable package asset
            // (the release workflow failed); the UI must warn, not offer it
            'package_asset' => $updateInfo['package_asset'] ?? false,
            'asset_name' => $updateInfo['asset_name'] ?? null
        ]);
    }

    /**
     * API: Get backup list
     */
    public function getBackups(Request $request, Response $response): Response
    {
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        $updater = new Updater($this->db);
        $backups = $updater->getBackupList();

        return $this->jsonResponse($response, ['backups' => $backups]);
    }

    /**
     * API: Delete a backup
     */
    public function deleteBackup(Request $request, Response $response): Response
    {
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf'] ?? $data['csrf_token'] ?? '';

        if (empty($csrfToken) || !hash_equals($_SESSION['csrf'] ?? '', $csrfToken)) {
            return $this->jsonResponse($response, ['error' => 'Invalid CSRF token'], 403);
        }

        $backupName = $data['backup'] ?? '';
        if (empty($backupName)) {
            return $this->jsonResponse($response, ['error' => 'Backup name not specified'], 400);
        }

        $updater = new Updater($this->db);
        $result = $updater->deleteBackup($backupName);

        if ($result['success']) {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Backup deleted successfully'
            ]);
        }

        return $this->jsonResponse($response, [
            'success' => false,
            'error' => $result['error']
        ], 500);
    }

    /**
     * Download a backup file
     */
    public function downloadBackup(Request $request, Response $response): Response
    {
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        $backupName = $request->getQueryParams()['backup'] ?? '';
        if (empty($backupName)) {
            return $this->jsonResponse($response, ['error' => 'Backup name not specified'], 400);
        }

        $updater = new Updater($this->db);
        $result = $updater->getBackupDownloadPath($backupName);

        if (!$result['success']) {
            return $this->jsonResponse($response, ['error' => $result['error']], 404);
        }

        $content = file_get_contents($result['path']);
        if ($content === false) {
            return $this->jsonResponse($response, ['error' => 'Cannot read backup file'], 500);
        }

        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'application/sql')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($content));
    }

    /**
     * API: Clear maintenance mode (emergency recovery)
     */
    public function clearMaintenance(Request $request, Response $response): Response
    {
        // Admin-only access
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        // Verify CSRF token
        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf'] ?? $data['csrf_token'] ?? '';

        if (empty($csrfToken) || !hash_equals($_SESSION['csrf'] ?? '', $csrfToken)) {
            return $this->jsonResponse($response, ['error' => 'Invalid CSRF token'], 403);
        }

        $maintenanceFile = dirname(__DIR__, 3) . '/storage/.maintenance';

        if (file_exists($maintenanceFile)) {
            if (@unlink($maintenanceFile)) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => 'Maintenance mode disabled'
                ]);
            } else {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Cannot delete maintenance file'
                ], 500);
            }
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'message' => 'Maintenance mode was not active'
        ]);
    }

    /**
     * API: Save (or clear) the GitHub API token, encrypted at rest.
     *
     * Stores the token under settings key `updater.github_token` via
     * SecretBox (libsodium). An empty submission clears it. The token value
     * is never echoed back or logged.
     */
    public function saveToken(Request $request, Response $response): Response
    {
        if ($request->getMethod() !== 'POST') {
            return $this->jsonResponse($response, ['error' => 'method_not_allowed'], 405);
        }
        if (($_SESSION['admin_role'] ?? '') !== 'admin') {
            return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
        }

        $data = (array) $request->getParsedBody();
        $csrfToken = $data['csrf'] ?? $data['csrf_token'] ?? '';
        if (empty($csrfToken) || !hash_equals($_SESSION['csrf'] ?? '', $csrfToken)) {
            return $this->jsonResponse($response, ['error' => 'Invalid CSRF token'], 403);
        }

        $token = trim((string) ($data['github_token'] ?? ''));
        $settings = new \App\Services\SettingsService($this->db);

        // Empty submission clears the stored token.
        if ($token === '') {
            $settings->set('updater.github_token', '');
            return $this->jsonResponse($response, [
                'success' => true,
                'configured' => false,
                'message' => 'GitHub token cleared',
            ]);
        }

        if (!\App\Support\SecretBox::isAvailable()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Encryption unavailable (set SESSION_SECRET and enable libsodium). Use the UPDATER_GITHUB_TOKEN env var instead.',
            ], 400);
        }

        $encrypted = \App\Support\SecretBox::encrypt($token);
        if ($encrypted === null) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Failed to encrypt the token',
            ], 500);
        }

        $settings->set('updater.github_token', $encrypted);

        return $this->jsonResponse($response, [
            'success' => true,
            'configured' => true,
            'message' => 'GitHub token saved',
        ]);
    }
}
