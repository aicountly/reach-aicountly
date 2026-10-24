<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use Tests\Support\ApiTestCase;

/**
 * Video item detail (v1/video/pipeline/{uuid}): its step jobs and staff
 * history; Video Operations' per-status counts that link to /video?status=.
 *
 * @internal
 */
final class VideoPipelineDetailApiTest extends ApiTestCase
{
    private function item(string $key, string $format, string $status): array
    {
        $db = \Config\Database::connect();
        $db->table('reach_video_pipeline_items')->insert([
            'external_key' => $key, 'format' => $format, 'title' => "Video {$key}",
            'submitted_json' => '{}', 'script_json' => '{}', 'status' => $status,
        ]);
        $id = (int) $db->insertID();

        return $db->table('reach_video_pipeline_items')->where('id', $id)->get()->getRowArray();
    }

    public function testDetailListsStepJobsAndHistory(): void
    {
        $item  = $this->item('a', 'vlog', 'failed');
        $other = $this->item('b', 'short', 'published');
        $db    = \Config\Database::connect();
        foreach ([[$item, 'vet', 'completed'], [$item, 'tts', 'dead_letter'], [$other, 'vet', 'completed']] as [$it, $step, $status]) {
            $db->table('reach_jobs')->insert([
                'job_type' => 'reach.video_pipeline_step', 'queue' => 'video', 'status' => $status,
                'payload_json' => json_encode(['item_id' => (int) $it['id'], 'step' => $step]),
            ]);
        }
        $db->table('reach_audit_logs')->insert(['action' => 'video.pipeline.retry', 'entity_type' => 'video_pipeline', 'entity_id' => (int) $item['id']]);

        $res = $this->withHeaders($this->authAs('super_admin'))->get('v1/video/pipeline/' . $item['uuid']);
        $res->assertStatus(200);
        $data = json_decode((string) $res->getJSON(), true)['data'];

        $this->assertSame(['vet', 'tts'], array_column($data['jobs'], 'step'));
        $this->assertSame(['completed', 'dead_letter'], array_column($data['jobs'], 'status'));
        $this->assertIsInt($data['jobs'][0]['id']);
        $this->assertSame(['video.pipeline.retry'], array_column($data['history'], 'action'));
    }

    public function testOperationsCountsByStatusAndFormat(): void
    {
        $this->item('a', 'vlog', 'failed');
        $this->item('b', 'short', 'failed');
        $this->item('c', 'short', 'published');

        $res = $this->withHeaders($this->authAs('super_admin'))->get('v1/video/operations');
        $res->assertStatus(200);
        $counts = json_decode((string) $res->getJSON(), true)['data']['pipeline_counts'];

        $this->assertSame(3, $counts['total']);
        $this->assertSame(['total' => 2, 'vlog' => 1, 'short' => 1], $counts['by_status']['failed']);
        $this->assertSame(['total' => 1, 'vlog' => 0, 'short' => 1], $counts['by_status']['published']);

        // The same predicate as the list each count opens.
        $list = json_decode((string) $this->withHeaders($this->authAs('super_admin'))->get('v1/video/pipeline?status=failed&format=short')->getJSON(), true)['data'];
        $this->assertSame($counts['by_status']['failed']['short'], $list['total']);
    }
}
