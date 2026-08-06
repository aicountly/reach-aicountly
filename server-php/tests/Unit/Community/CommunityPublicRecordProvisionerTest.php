<?php

declare(strict_types=1);

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityPublicRecordProvisioner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Community\CommunityPublicRecordProvisioner
 */
class CommunityPublicRecordProvisionerTest extends CIUnitTestCase
{
    /**
     * Reach names operational roles after the desk; the public receiver names
     * them after the function, and rejects anything outside its own enum with a
     * 422. Every Reach role must therefore land on a value the receiver accepts.
     */
    public function testEveryReachRoleMapsToAReceiverRole(): void
    {
        $receiverRoles = ['answering', 'facilitation', 'moderation', 'curation', 'editorial'];

        $expected = [
            'expert_answer_assistant' => 'answering',
            'thread_facilitator'      => 'facilitation',
            'community_steward'       => 'moderation',
            'question_curator'        => 'curation',
            'review_objection_desk'   => 'editorial',
        ];

        foreach ($expected as $reachRole => $publicRole) {
            $mapped = CommunityPublicRecordProvisioner::publicRoleFor($reachRole);
            $this->assertSame($publicRole, $mapped);
            $this->assertContains($mapped, $receiverRoles);
        }
    }

    /**
     * An unmapped or missing role must still produce a value the receiver
     * accepts — refusing to publish over a vocabulary gap would be worse than
     * posting under the most common role.
     */
    public function testUnknownRoleFallsBackToAnAcceptedRole(): void
    {
        foreach ([null, '', 'something_new', 'QUESTION_CURATOR '] as $role) {
            $mapped = CommunityPublicRecordProvisioner::publicRoleFor($role);
            $this->assertContains($mapped, ['answering', 'facilitation', 'moderation', 'curation', 'editorial']);
        }

        $this->assertSame('answering', CommunityPublicRecordProvisioner::publicRoleFor('something_new'));
        // Case and whitespace differences must not silently fall back.
        $this->assertSame('curation', CommunityPublicRecordProvisioner::publicRoleFor('QUESTION_CURATOR '));
    }
}
