<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\ActorRegistry;
use Tests\Support\DatabaseTestCase;

final class ActorRegistryTest extends DatabaseTestCase
{
    public function testIdForUserCreatesMatchingActorRow(): void
    {
        $db = \Config\Database::connect();
        $db->table('reach_roles')->insert([
            'slug'        => 'actor_test_role',
            'name'        => 'Actor Test Role',
            'description' => 'test',
            'permissions' => json_encode(['*']),
        ]);
        $roleId = (int) $db->insertID();

        $db->table('reach_users')->insert([
            'email'         => 'actor-registry@test.aicountly.org',
            'name'          => 'Actor Registry Test',
            'password_hash' => password_hash('test', PASSWORD_BCRYPT),
            'role_id'       => $roleId,
            'is_active'     => true,
            'actor_type'    => 'human',
        ]);
        $userId = (int) $db->insertID();

        $actorId = ActorRegistry::idForUser($userId);

        $this->assertSame($userId, $actorId);
        $actor = $db->table('reach_actors')->where('id', $userId)->get()->getRowArray();
        $this->assertNotNull($actor);
        $this->assertSame('actor-registry@test.aicountly.org', $actor['email']);
    }
}
