<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\BaseController;
use App\Support\AdminSession;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

/** Regressions for audit findings C7 (credential-bound sessions) and C14 (session-only isAdmin). */
final class AdminSessionRevocationTest extends TestCase
{
    private string $dbFile;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/cimaise_admsess_' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database(database: $this->dbFile, isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $this->db->pdo()->exec("INSERT INTO users (id, email, password_hash, role, is_active) VALUES (2, 'co@x.test', 'HASH-A', 'admin', 1)");
        AdminSession::setDatabase($this->db);
        $_SESSION = ['admin_id' => 2];
        AdminSession::bind('HASH-A');
    }

    protected function tearDown(): void
    {
        AdminSession::setDatabase(null);
        $_SESSION = [];
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- teardown of this test's own temp database.
        @unlink($this->dbFile);
    }

    private function refresh(): void
    {
        AdminSession::setDatabase($this->db); // new request: drop the per-request memo
    }

    public function testActiveAdminWithBoundSessionIsCurrent(): void
    {
        self::assertTrue(AdminSession::isCurrent());
        self::assertTrue((new AdminSessionHarness())->admin());
    }

    public function testDeactivatedAdminLosesFrontendAdminTreatment(): void
    {
        $this->db->pdo()->exec('UPDATE users SET is_active = 0 WHERE id = 2');
        $this->refresh();
        self::assertFalse(AdminSession::isCurrent());
        self::assertFalse((new AdminSessionHarness())->admin());
    }

    public function testDemotedOrDeletedAdminIsNotCurrent(): void
    {
        $this->db->pdo()->exec("UPDATE users SET role = 'user' WHERE id = 2");
        $this->refresh();
        self::assertFalse(AdminSession::isCurrent());
        $this->db->pdo()->exec('DELETE FROM users WHERE id = 2');
        $this->refresh();
        self::assertFalse(AdminSession::isCurrent());
    }

    public function testPasswordChangeRevokesOtherSessions(): void
    {
        $this->db->pdo()->exec("UPDATE users SET password_hash = 'HASH-B' WHERE id = 2");
        $this->refresh();
        self::assertFalse(AdminSession::isCurrent(), 'old session must not survive a password change');
        AdminSession::bind('HASH-B'); // the session that changed the password re-binds
        self::assertTrue(AdminSession::isCurrent());
    }

    public function testUnboundSessionIsNotTrusted(): void
    {
        unset($_SESSION[AdminSession::FINGERPRINT_KEY]);
        $this->refresh();
        self::assertFalse(AdminSession::isCurrent());
    }

    public function testRememberTokensAreRevokedByAdminResetAndDeactivation(): void
    {
        $users = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/UsersController.php');
        self::assertStringContainsString('remember_token = NULL, remember_token_expires_at = NULL,', $users);
        self::assertStringContainsString('remember_token = CASE WHEN :status2 = 0 THEN NULL', $users);
    }

    public function testCliPasswordResetRevokesOnlyThatUsersRememberToken(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("UPDATE users SET remember_token = 'tok-a', remember_token_expires_at = '2099-01-01 00:00:00' WHERE id = 2");
        $pdo->exec("INSERT INTO users (id, email, password_hash, role, is_active, remember_token, remember_token_expires_at) VALUES (3, 'other@x.test', 'HASH-O', 'admin', 1, 'tok-b', '2099-01-01 00:00:00')");

        $app = new \Symfony\Component\Console\Application();
        $app->add(new \App\Tasks\UserUpdateCommand($this->db));
        $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find('user:update'));
        $tester->setInputs(['', 'NewPass-123456', 'NewPass-123456']); // keep email, new password twice
        $tester->execute(['email' => 'co@x.test'], ['interactive' => true]);

        $row = $pdo->query('SELECT password_hash, remember_token, remember_token_expires_at FROM users WHERE id = 2')->fetch();
        self::assertTrue(password_verify('NewPass-123456', (string) $row['password_hash']));
        self::assertNull($row['remember_token']);
        self::assertNull($row['remember_token_expires_at']);
        $other = $pdo->query('SELECT remember_token FROM users WHERE id = 3')->fetchColumn();
        self::assertSame('tok-b', $other);
    }

    public function testStaleSessionSeesTheLoginFormInsteadOfARedirect(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/AuthController.php');
        self::assertStringContainsString("AdminSession::isCurrent()) {\n                return \$response->withHeader('Location', \$this->redirect('/admin'))", $src);
    }

    public function testPasswordChangeRevokesRememberTokenInTheSameStatement(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/AuthController.php');
        self::assertStringContainsString('UPDATE users SET password_hash = :password_hash, remember_token = NULL, remember_token_expires_at = NULL', $src);
    }

    public function testLoginIssuesRememberTokenOnlyForTheVerifiedCredential(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/AuthController.php');
        self::assertStringContainsString("WHERE id = :id AND password_hash = :password_hash AND is_active = 1 AND role = 'admin'", $src);
        self::assertStringContainsString('$this->setRememberToken((int)$user[\'id\'], (string) $user[\'password_hash\']);', $src);
    }

    /** Run AuthController::setRememberToken() against the temp database. */
    private function issueRememberToken(string $verifiedHash): bool
    {
        $controller = new \App\Controllers\Admin\AuthController(
            $this->db,
            \Slim\Views\Twig::create(dirname(__DIR__, 2) . '/app/Views'),
            new \App\Services\SettingsService($this->db)
        );
        $method = new \ReflectionMethod($controller, 'setRememberToken');
        return (bool) $method->invoke($controller, 2, $verifiedHash);
    }

    private function storedRememberToken(): ?string
    {
        $value = $this->db->pdo()->query('SELECT remember_token FROM users WHERE id = 2')->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    public function testRememberTokenIsIssuedForTheVerifiedCredential(): void
    {
        self::assertTrue($this->issueRememberToken('HASH-A'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $this->storedRememberToken());
    }

    /** @return array<string, array{string, string}> */
    public static function changedCredentials(): array
    {
        return [
            'password changed during login' => ["UPDATE users SET password_hash = 'HASH-B' WHERE id = 2", 'HASH-A'],
            'account deactivated' => ['UPDATE users SET is_active = 0 WHERE id = 2', 'HASH-A'],
            'account demoted' => ["UPDATE users SET role = 'user' WHERE id = 2", 'HASH-A'],
        ];
    }

    /** @dataProvider changedCredentials */
    public function testRememberTokenIsNotIssuedWhenCredentialsChanged(string $change, string $verifiedHash): void
    {
        $this->db->pdo()->exec("UPDATE users SET remember_token = 'OLD-TOKEN' WHERE id = 2");
        $this->db->pdo()->exec($change);

        self::assertFalse($this->issueRememberToken($verifiedHash), 'no cookie may be issued');
        self::assertSame('OLD-TOKEN', $this->storedRememberToken(), 'the stored token must stay untouched');
    }

    public function testGpsPolicyChangeClearsThePageCache(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/SeoController.php');
        self::assertStringContainsString('if ($exposeGpsBefore !== $exposeGpsAfter) {', $src);
        self::assertStringContainsString('->clearAll();', $src);
    }

    public function testRememberRotationIsACompareAndSet(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Middlewares/AuthMiddleware.php');
        self::assertStringContainsString('AND remember_token = :previous AND password_hash = :password_hash', $src);
        self::assertStringContainsString('if ($stmt->rowCount() !== 1)', $src);
    }
}

final class AdminSessionHarness extends BaseController
{
    public function __construct()
    {
    }

    public function admin(): bool
    {
        return $this->isAdmin();
    }

    protected function ensureSession(): void
    {
    }
}
