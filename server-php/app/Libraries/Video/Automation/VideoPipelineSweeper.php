<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

/**
 * Hourly housekeeping for the video pipeline (reach.video_pipeline_sweep):
 *   - re-vet scripts left pending because the vetting model was unavailable
 *   - retry blocked items (an operator may have added the key / connected the
 *     channel since); a step that is still blocked simply blocks again
 *   - re-queue in-progress items whose step job was lost
 */
final class VideoPipelineSweeper
{
    public function __construct(private readonly VideoPipelineRepository $repo = new VideoPipelineRepository())
    {
    }

    /** @return array<string, int> */
    public function sweep(): array
    {
        return [
            'revetted' => $this->revetPending(),
            'released' => $this->releaseBlocked(),
            'requeued' => $this->requeueStalled(),
        ];
    }

    public function revetPending(int $limit = 5): int
    {
        $rows = $this->repo->db()->table('reach_video_pipeline_items')
            ->select('id')->where('status', 'pending_vetting')
            ->where('updated_at <', gmdate('Y-m-d H:i:s', time() - 300))
            ->orderBy('id')->limit($limit)->get()->getResultArray();
        $service = new VideoAutomationService($this->repo);
        $n = 0;
        foreach ($rows as $row) {
            try {
                $service->vet((int) $row['id']);
                $n++;
            } catch (\Throwable $e) {
                log_message('error', '[VideoSweep] re-vet failed for item ' . $row['id'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /** @param list<string>|null $steps only items blocked at these steps */
    public function releaseBlocked(?array $steps = null): int
    {
        $q = $this->repo->db()->table('reach_video_pipeline_items')->select('id, step')->where('status', 'blocked');
        if ($steps !== null) {
            $q->whereIn('step', $steps);
        }
        $n = 0;
        foreach ($q->get()->getResultArray() as $row) {
            $this->repo->update((int) $row['id'], ['status' => 'in_progress', 'step_detail' => 'Retrying after being blocked.']);
            VideoPipelineRunner::enqueue((int) $row['id'], (string) $row['step']);
            $n++;
        }
        return $n;
    }

    public function requeueStalled(int $staleMinutes = 30): int
    {
        $rows = $this->repo->db()->query(
            "SELECT i.id, i.step FROM reach_video_pipeline_items i
              WHERE i.status = 'in_progress' AND i.updated_at < NOW() - make_interval(mins => ?)
                AND NOT EXISTS (
                    SELECT 1 FROM reach_jobs j
                     WHERE j.job_type = ? AND j.status IN ('pending','processing','reserved')
                       AND j.payload_json->>'item_id' = i.id::text)",
            [$staleMinutes, VideoPipelineRunner::JOB_TYPE],
        )->getResultArray();
        foreach ($rows as $row) {
            VideoPipelineRunner::enqueue((int) $row['id'], (string) $row['step']);
        }
        return count($rows);
    }
}
