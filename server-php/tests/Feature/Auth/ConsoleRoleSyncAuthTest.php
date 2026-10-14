<?php

namespace Tests\Feature\Auth;

use App\Libraries\ConsoleRoleSync;
use App\Models\SessionModel;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use Tests\Support\ApiTestCase;
use Tests\Support\FakeConsoleIdentity;

/**
 * Reach staff roles come from Console (SSO payload at login, user-access
 * pull at refresh). Console is stubbed — no HTTP leaves the test. Roles used
 * here are the production rows the role-sync migration guarantees.
 */
final class ConsoleRoleSyncAuthTest extends ApiTestCase
{
    private FakeConsoleIdentity $console;

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(false);
        $this->console = new FakeConsoleIdentity();
        Services::injectMock('consoleIdentity', $this->console);
    }

    protected function tearDown(): void
    {
        Services::reset(false);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function identity(array $extra = [], string $email = 'ravi@aicountly.org'): array
    {
        return array_merge([
            'active'            => true,
            'global_superadmin' => false,
            'user'              => ['id' => 77, 'name' => 'Ravi', 'email' => $email, 'role' => 'staff'],
            'controller_grants' => [],
            'app_code'          => 'reach',
        ], $extra);
    }

    private function login(array $identity): TestResponse
    {
        $this->console->identity = $identity;

        return $this->withBodyFormat('json')->call('POST', 'v1/auth/controller-sso', ['token' => 'launch-token']);
    }

    private function body(TestResponse $r): array
    {
        return json_decode((string) $r->response()->getBody(), true) ?? [];
    }

    private function roleId(string $slug): int
    {
        return (int) Database::connect()->table('reach_roles')->where('slug', $slug)->get()->getRowArray()['id'];
    }

    private function userRow(string $email = 'ravi@aicountly.org'): ?array
    {
        return Database::connect()->table('reach_users u')
            ->select('u.*, r.slug AS role_slug')
            ->join('reach_roles r', 'r.id = u.role_id', 'left')
            ->where('u.email', $email)->get()->getRowArray() ?: null;
    }

    private function seedUser(string $roleSlug, array $cols = [], string $email = 'ravi@aicountly.org'): int
    {
        $db = Database::connect();
        $db->table('reach_users')->insert(array_merge([
            'email' => $email, 'name' => 'Ravi', 'password_hash' => 'x',
            'role_id' => $this->roleId($roleSlug), 'is_active' => true,
        ], $cols));

        return (int) $db->insertID();
    }

    /** A JWT with a live reach_sessions row, as a real login would leave. */
    private function sessionToken(int $userId, string $roleSlug): string
    {
        $token = Services::jwt()->issue($userId, 'ravi@aicountly.org', $roleSlug, 'Ravi');
        (new SessionModel())->createFromToken($userId, $token, Services::jwt()->ttlSeconds(), '127.0.0.1', 'phpunit');

        return $token;
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    private function refresh(string $token): TestResponse
    {
        return $this->withBodyFormat('json')->call('POST', 'v1/auth/refresh', ['token' => $token]);
    }

    private function userAccess(array $roles, array $extra = []): array
    {
        return ['status' => 'ok', 'data' => array_merge([
            'exists' => true, 'active' => true, 'app_access' => true, 'app_superadmin' => false,
            'console_user_id' => 77, 'email' => 'ravi@aicountly.org', 'name' => 'Ravi',
            'app_roles' => $roles, 'authz_version' => 3,
        ], $extra)];
    }

    // ------------------------------------------------------------------ login

    public function testMigrationGuaranteesConsoleAssignableRoles(): void
    {
        foreach (ConsoleRoleSync::RANKED as $slug) {
            $this->assertGreaterThan(0, $this->roleId($slug), $slug);
        }
    }

    public function testContentReviewerCanNowSignInAndSeesOnlyWhatTheyMayUse(): void
    {
        $r = $this->login($this->identity([
            'console_user_id' => 77, 'app_roles' => ['content_reviewer'], 'app_superadmin' => false, 'authz_version' => 2,
        ]));

        $this->assertSame(200, $r->response()->getStatusCode(), (string) $r->response()->getBody());
        $data = $this->body($r)['data'];
        $this->assertSame('content_reviewer', $data['user']['role']);
        $this->assertNotContains('*', $data['user']['permissions']);
        $this->assertContains('blog.approve', $data['user']['permissions']);
        $this->assertNotContains('blog.publish', $data['user']['permissions']);
        $this->assertNotContains('settings.view', $data['user']['permissions']);

        $row = $this->userRow();
        $this->assertSame('content_reviewer', $row['role_slug']);
        $this->assertSame(77, (int) $row['console_user_id']);
        $this->assertSame(2, (int) $row['authz_version']);
        $this->assertNotEmpty($row['roles_synced_at']);

        $headers = $this->bearer($data['token']);
        $this->assertSame(200, $this->withHeaders($headers)->call('GET', 'v1/me')->response()->getStatusCode());
        $this->assertSame(200, $this->withHeaders($headers)->call('GET', 'v1/dashboard/counts')->response()->getStatusCode());
        $this->assertSame(403, $this->withHeaders($headers)->call('GET', 'v1/settings')->response()->getStatusCode());
    }

    public function testExistingNonSuperUserIsNoLongerRefused(): void
    {
        $this->seedUser('marketing_manager');

        $r = $this->login($this->identity(['app_roles' => ['marketing_manager'], 'app_superadmin' => false]));

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame('marketing_manager', $this->body($r)['data']['user']['role']);
    }

    public function testHighestRankedRoleWinsAndReplacesTheLocalRole(): void
    {
        $id = $this->seedUser('super_admin');
        // Portal-local per-user override must survive the sync.
        Database::connect()->table('reach_user_permissions')->insert([
            'user_id' => $id, 'permission' => 'analytics.view', 'mode' => 'grant',
        ]);

        $r = $this->login($this->identity(['app_roles' => ['content_reviewer', 'reach_admin'], 'app_superadmin' => false]));

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame('reach_admin', $this->userRow()['role_slug']);
        $this->assertSame(1, Database::connect()->table('reach_user_permissions')->where('user_id', $id)->countAllResults());
    }

    public function testEmptyAppRolesIsRefusedWith403AndNothingProvisioned(): void
    {
        $r = $this->login($this->identity(['app_roles' => [], 'app_superadmin' => false]));

        $this->assertSame(403, $r->response()->getStatusCode());
        $this->assertSame(ConsoleRoleSync::DENY_MESSAGE, $this->body($r)['error']);
        $this->assertNull($this->userRow());
    }

    public function testBrowserSsoCallbackShowsTheAccessNotAssignedMessage(): void
    {
        $this->console->identity = $this->identity(['app_roles' => [], 'app_superadmin' => false]);

        $r = $this->call('GET', 'v1/auth/sso-callback', ['token' => 'launch-token']);

        $this->assertSame(403, $r->response()->getStatusCode());
        $this->assertStringContainsString(ConsoleRoleSync::DENY_MESSAGE, (string) $r->response()->getBody());
    }

    public function testAppSuperadminGetsSuperAdmin(): void
    {
        $r = $this->login($this->identity(['app_roles' => [], 'app_superadmin' => true, 'global_superadmin' => true]));

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame('super_admin', $this->body($r)['data']['user']['role']);
    }

    public function testOldPayloadNewNonSuperadminIsRefused(): void
    {
        $r = $this->login($this->identity()); // no app_roles key

        $this->assertSame(403, $r->response()->getStatusCode());
        $this->assertNull($this->userRow());
    }

    public function testOldPayloadGlobalSuperadminIsProvisionedAsSuperAdmin(): void
    {
        $r = $this->login($this->identity(['global_superadmin' => true]));

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame('super_admin', $this->userRow()['role_slug']);
        $this->assertNull($this->userRow()['roles_synced_at'], 'legacy login is not a Console-confirmed sync');
    }

    public function testOldPayloadExistingUserKeepsLocalRole(): void
    {
        $this->seedUser('content_reviewer');

        $r = $this->login($this->identity());

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame('content_reviewer', $this->body($r)['data']['user']['role']);
    }

    // ------------------------------------------------------------------ refresh

    public function testRefreshResyncsRole(): void
    {
        $id    = $this->seedUser('content_reviewer', ['console_user_id' => 77]);
        $token = $this->sessionToken($id, 'content_reviewer');
        $this->console->pullResult = $this->userAccess(['marketing_manager'], ['authz_version' => 8]);

        $r = $this->refresh($token);

        $this->assertSame(200, $r->response()->getStatusCode(), (string) $r->response()->getBody());
        $this->assertSame('marketing_manager', $this->body($r)['data']['role']);
        $this->assertSame('marketing_manager', Services::jwt()->decode($this->body($r)['data']['token'])['role']);
        $this->assertSame('marketing_manager', $this->userRow()['role_slug']);
        $this->assertSame(8, (int) $this->userRow()['authz_version']);
        $this->assertSame(77, $this->console->pullCalls[0]['console_user_id']);
    }

    public function testRefreshRevokedIsRefusedAndEndsAllSessions(): void
    {
        $id     = $this->seedUser('super_admin', ['console_user_id' => 77]);
        $token  = $this->sessionToken($id, 'super_admin');
        $other  = $this->sessionToken($id, 'super_admin');
        $this->assertSame(200, $this->withHeaders($this->bearer($other))->call('GET', 'v1/me')->response()->getStatusCode());

        $this->console->pullResult = $this->userAccess([], ['app_access' => false]);

        $this->assertSame(403, $this->refresh($token)->response()->getStatusCode());
        $this->assertSame(0, Database::connect()->table('reach_sessions')
            ->where('user_id', $id)->where('revoked_at', null)->countAllResults());
        $this->assertSame(401, $this->withHeaders($this->bearer($other))->call('GET', 'v1/me')->response()->getStatusCode());
    }

    public function testRefreshConsoleDownWithinGraceIsAllowed(): void
    {
        $id    = $this->seedUser('content_reviewer', ['roles_synced_at' => gmdate('Y-m-d H:i:s', time() - 3600) . '+00']);
        $token = $this->sessionToken($id, 'content_reviewer');
        $this->console->pullResult = ['status' => 'unreachable'];

        $r = $this->refresh($token);

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame('content_reviewer', $this->body($r)['data']['role']);
    }

    public function testRefreshConsoleDownAfterGraceIsRefused(): void
    {
        $id    = $this->seedUser('content_reviewer', ['roles_synced_at' => gmdate('Y-m-d H:i:s', time() - 13 * 3600) . '+00']);
        $token = $this->sessionToken($id, 'content_reviewer');
        $this->console->pullResult = ['status' => 'unreachable'];

        $this->assertSame(503, $this->refresh($token)->response()->getStatusCode());
        // Console being down is not a revocation.
        $this->assertSame(1, Database::connect()->table('reach_sessions')
            ->where('user_id', $id)->where('revoked_at', null)->countAllResults());
    }

    public function testRefreshWithoutConsoleConfigKeepsOldBehaviourForAnyRole(): void
    {
        $id    = $this->seedUser('content_reviewer');
        $token = $this->sessionToken($id, 'content_reviewer');
        $this->console->pullConfigured = false;

        $r = $this->refresh($token);

        $this->assertSame(200, $r->response()->getStatusCode());
        $this->assertSame('content_reviewer', $this->body($r)['data']['role']);
        $this->assertSame([], $this->console->pullCalls);
    }

    public function testRevokedSessionCannotBeRefreshed(): void
    {
        $id    = $this->seedUser('super_admin');
        $token = $this->sessionToken($id, 'super_admin');
        Services::staffAccess(false)->revokeSessions($id);
        $this->console->pullConfigured = false;

        $this->assertSame(401, $this->refresh($token)->response()->getStatusCode());
    }
}
