<?php

namespace Tests\Support;

use App\Libraries\Jwt;
use App\Models\SessionModel;
use App\Models\UserModel;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Feature-test base for JWT-authenticated V1 API routes.
 *
 * Provides `authAs(string $roleSlug)` which seeds/finds a user with the
 * given role, issues a JWT + `reach_sessions` row, and returns headers to
 * attach on `withHeaders(...)->call(...)`.
 */
abstract class ApiTestCase extends DatabaseTestCase
{
    use FeatureTestTrait;

    /**
     * @return array{Authorization: string, Accept: string}
     */
    protected function authAs(string $roleSlug, array $overrides = []): array
    {
        Services::reset(true);

        $roleId = $this->ensureRole($roleSlug);
        $userId = $this->ensureUser($roleSlug, $roleId, $overrides);

        $jwt   = new Jwt();
        $email = $overrides['email'] ?? ($roleSlug . '@test.aicountly.org');
        $name  = $overrides['name']  ?? ucfirst(str_replace('_', ' ', $roleSlug));
        $token = $jwt->issue($userId, $email, $roleSlug, $name);

        (new SessionModel())->createFromToken(
            $userId,
            $token,
            $jwt->ttlSeconds(),
            '127.0.0.1',
            'phpunit-test-agent',
        );

        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];
    }

    protected function ensureRole(string $slug): int
    {
        $db = \Config\Database::connect();
        $row = $db->table('reach_roles')->where('slug', $slug)->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }
        $permissions = $slug === 'super_admin' ? ['*'] : [];
        $db->table('reach_roles')->insert([
            'slug'        => $slug,
            'name'        => ucfirst(str_replace('_', ' ', $slug)),
            'description' => 'Seeded by ApiTestCase',
            'permissions' => json_encode($permissions),
        ]);
        return (int) $db->insertID();
    }

    protected function ensureUser(string $slug, int $roleId, array $overrides): int
    {
        $email = $overrides['email'] ?? ($slug . '@test.aicountly.org');
        $model = new UserModel();
        $existing = $model->where('email', $email)->first();
        if ($existing) {
            return (int) $existing['id'];
        }
        $model->insert([
            'email'         => $email,
            'name'          => $overrides['name'] ?? ucfirst(str_replace('_', ' ', $slug)),
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
            'role_id'       => $roleId,
            'is_active'     => true,
        ]);
        return (int) $model->getInsertID();
    }
}
