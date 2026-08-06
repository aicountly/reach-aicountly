<?php

namespace Tests\Feature\Blog;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Cover for Blog Command Centre → Roadmap → Topic Candidates.
 *
 * The listing returned a bare 500: the candidate query joined the scores
 * table with a two-clause string ON condition, and the query builder escapes
 * every token of a string ON clause as an identifier — so the boolean literal
 * compiled to `"ts"."is_current" = "true"` and Postgres answered
 * `column "true" does not exist`. The UI only ever saw "Request failed with
 * status 500" over an empty-state placeholder, so the tab looked merely empty
 * rather than broken.
 *
 * These tests pin the query actually executing, the score join attaching, the
 * ordering (scored candidates above unscored ones), and both filters the web
 * client sends.
 */
final class RoadmapCandidatesApiTest extends ApiTestCase
{
    private function fetch(array $headers, string $query = ''): array
    {
        $response = $this->withHeaders($headers)->call('GET', 'v1/blog-command-centre/roadmap/candidates' . $query);
        $this->assertSame(
            200,
            $response->response()->getStatusCode(),
            'Roadmap candidates must not 500: ' . $response->response()->getBody(),
        );

        return json_decode($response->response()->getBody(), true)['data'] ?? [];
    }

    private function seedCandidate(string $title, string $stream, string $status): int
    {
        $db = Database::connect();
        $db->table('reach_topic_candidates')->insert([
            'title'            => $title,
            'portfolio_stream' => $stream,
            'status'           => $status,
        ]);

        return (int) $db->insertID();
    }

    private function seedScore(int $candidateId, float $score, bool $isCurrent = true): void
    {
        Database::connect()->table('reach_topic_scores')->insert([
            'topic_candidate_id' => $candidateId,
            'total_score'        => $score,
            'scored_for_date'    => date('Y-m-d'),
            'is_current'         => $isCurrent,
        ]);
    }

    public function testEmptyRoadmapReturnsAnEmptyListingNotAnError(): void
    {
        $listing = $this->fetch($this->authAs('reach_admin'));

        $this->assertSame(0, $listing['total']);
        $this->assertSame([], $listing['items']);
    }

    public function testScoredAndUnscoredCandidatesBothListWithScoresFirst(): void
    {
        $headers = $this->authAs('reach_admin');

        $low  = $this->seedCandidate('Low scorer', 'product', 'candidate');
        $high = $this->seedCandidate('High scorer', 'product', 'candidate');
        $this->seedCandidate('Never scored', 'problem', 'candidate');
        $this->seedScore($low, 11.5);
        $this->seedScore($high, 88.25);

        $listing = $this->fetch($headers);

        $this->assertSame(3, $listing['total']);
        $this->assertCount(3, $listing['items']);

        $titles = array_column($listing['items'], 'title');
        $this->assertSame(
            ['High scorer', 'Low scorer', 'Never scored'],
            $titles,
            'Highest score first; unscored candidates sort last',
        );

        $this->assertSame(88.25, (float) $listing['items'][0]['total_score']);
        $this->assertNull($listing['items'][2]['total_score'], 'Unscored candidate joins to a NULL score');
    }

    public function testOnlyTheCurrentScoreIsJoined(): void
    {
        $headers = $this->authAs('reach_admin');

        $id = $this->seedCandidate('Rescored topic', 'product', 'candidate');
        $this->seedScore($id, 20.0, false);
        $this->seedScore($id, 70.0, true);

        $listing = $this->fetch($headers);

        $this->assertSame(1, $listing['total'], 'A superseded score must not duplicate the candidate row');
        $this->assertSame(70.0, (float) $listing['items'][0]['total_score']);
    }

    public function testStatusAndStreamFiltersNarrowTheListing(): void
    {
        $headers = $this->authAs('reach_admin');

        $this->seedCandidate('Product candidate', 'product', 'candidate');
        $this->seedCandidate('Problem candidate', 'problem', 'candidate');
        $this->seedCandidate('Selected product', 'product', 'roadmap_selected');

        $byStatus = $this->fetch($headers, '?status=candidate');
        $this->assertSame(2, $byStatus['total']);

        $byStream = $this->fetch($headers, '?portfolio_stream=product');
        $this->assertSame(2, $byStream['total']);

        $both = $this->fetch($headers, '?status=candidate&portfolio_stream=product');
        $this->assertSame(1, $both['total']);
        $this->assertSame('Product candidate', $both['items'][0]['title']);
    }

    public function testListingRequiresBlogViewPermission(): void
    {
        $response = $this->withHeaders($this->authAs('knowledge_viewer'))
            ->call('GET', 'v1/blog-command-centre/roadmap/candidates');

        $this->assertSame(403, $response->response()->getStatusCode());
    }
}
