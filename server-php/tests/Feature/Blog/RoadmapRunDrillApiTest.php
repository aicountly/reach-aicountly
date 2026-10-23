<?php

namespace Tests\Feature\Blog;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * The optimiser run table shows Scored / Decisions / Work blocks counts per
 * run; each count opens the rows it counted. These pin the filters behind
 * those links: decisions by run (and those that queued a work block), and
 * scores written for one run date.
 */
final class RoadmapRunDrillApiTest extends ApiTestCase
{
    private function fetch(string $path): array
    {
        $response = $this->withHeaders($this->authAs('reach_admin'))->call('GET', $path);
        $this->assertSame(200, $response->response()->getStatusCode(), (string) $response->response()->getBody());

        return json_decode($response->response()->getBody(), true)['data'] ?? [];
    }

    private function seedCandidate(string $title): int
    {
        $db = Database::connect();
        $db->table('reach_topic_candidates')->insert([
            'title'            => $title,
            'portfolio_stream' => 'product',
            'status'           => 'scored',
        ]);

        return (int) $db->insertID();
    }

    private function seedDecision(int $candidateId, int $runId, ?int $workBlockId = null): void
    {
        Database::connect()->table('reach_roadmap_decisions')->insert([
            'topic_candidate_id' => $candidateId,
            'decision'           => 'CREATE_NEW',
            'optimizer_run_id'   => $runId,
            'work_block_id'      => $workBlockId,
            'decided_for_date'   => '2026-10-20',
        ]);
    }

    public function testDecisionsNarrowToOneRunAndToThoseWithAWorkBlock(): void
    {
        $a = $this->seedCandidate('Topic A');
        $b = $this->seedCandidate('Topic B');
        $this->seedDecision($a, 7, 99);
        $this->seedDecision($b, 7);
        $this->seedDecision($a, 8);

        $this->assertSame(3, $this->fetch('v1/blog-command-centre/roadmap/decisions')['total']);
        $this->assertSame(2, $this->fetch('v1/blog-command-centre/roadmap/decisions?run_id=7')['total']);

        $blocks = $this->fetch('v1/blog-command-centre/roadmap/decisions?run_id=7&has_work_block=1');
        $this->assertSame(1, $blocks['total']);
        $this->assertSame('Topic A', $blocks['items'][0]['title']);
    }

    public function testScoredForDateListsThatDaysScoresEvenWhenSuperseded(): void
    {
        $db = Database::connect();
        $id = $this->seedCandidate('Rescored');
        $db->table('reach_topic_scores')->insert([
            'topic_candidate_id' => $id, 'total_score' => 40, 'scored_for_date' => '2026-10-19', 'is_current' => false,
        ]);
        $db->table('reach_topic_scores')->insert([
            'topic_candidate_id' => $id, 'total_score' => 55, 'scored_for_date' => '2026-10-20', 'is_current' => true,
        ]);

        $current = $this->fetch('v1/blog-command-centre/roadmap/scored');
        $this->assertSame(1, $current['total']);
        $this->assertSame(55.0, (float) $current['items'][0]['total_score']);

        $older = $this->fetch('v1/blog-command-centre/roadmap/scored?scored_for_date=2026-10-19');
        $this->assertSame(1, $older['total']);
        $this->assertSame(40.0, (float) $older['items'][0]['total_score']);

        // A malformed date is ignored rather than sent to Postgres.
        $this->assertSame(1, $this->fetch('v1/blog-command-centre/roadmap/scored?scored_for_date=yesterday')['total']);
    }
}
