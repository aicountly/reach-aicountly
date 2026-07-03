<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ApprovalModel;
use App\Models\SocialPostModel;

class SocialPostController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new SocialPostModel();
        foreach (['channel', 'status', 'approval_status'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $q->where($f, $v);
            }
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('scheduled_at', 'ASC')->orderBy('updated_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)
    {
        $row = (new SocialPostModel())->find($id);
        if (! $row) {
            return $this->fail('Social post not found.', 404);
        }
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $m    = new SocialPostModel();
        $row  = $this->normalize($body);
        $row['created_by'] = $this->userId();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        $this->audit('social.create', 'social', $id, null, $row);
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m   = new SocialPostModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Social post not found.', 404);
        }
        $update = $this->normalize($this->input(), partial: true);
        $m->update($id, $update);
        $this->audit('social.update', 'social', $id, $row, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m = new SocialPostModel();
        if (! $m->find($id)) {
            return $this->fail('Social post not found.', 404);
        }
        $m->update($id, ['status' => 'archived']);
        $this->audit('social.archive', 'social', $id);
        return $this->ok(['message' => 'Archived.']);
    }

    public function approve(int $id)
    {
        $m   = new SocialPostModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Social post not found.', 404);
        }
        // If no provider token is configured for that channel, route to manual_queue.
        $envKey = match ($row['channel'] ?? '') {
            'linkedin'        => 'LINKEDIN_API_TOKEN',
            'twitter'         => 'TWITTER_API_TOKEN',
            'facebook'        => 'FACEBOOK_API_TOKEN',
            'instagram'       => 'INSTAGRAM_API_TOKEN',
            'youtube'         => 'YOUTUBE_API_TOKEN',
            'whatsapp_channel'=> 'WHATSAPP_CHANNEL_API_TOKEN',
            default           => '',
        };
        $status = $envKey !== '' && env($envKey, '') !== '' ? 'approved' : 'manual_queue';
        $m->update($id, [
            'status'          => $status,
            'approval_status' => 'approved',
            'approved_by'     => $this->userId(),
            'approved_at'     => date('Y-m-d H:i:s'),
        ]);
        (new ApprovalModel())->insert([
            'subject_type' => 'social',
            'subject_id'   => $id,
            'summary'      => 'Social post approved',
            'requested_by' => $row['created_by'],
            'decision'     => 'approved',
            'decided_by'   => $this->userId(),
            'decided_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->audit('social.approve', 'social', $id, $row, ['status' => $status]);
        return $this->ok($m->find($id));
    }

    public function reject(int $id)
    {
        $m   = new SocialPostModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Social post not found.', 404);
        }
        $m->update($id, ['approval_status' => 'rejected', 'status' => 'archived']);
        $this->audit('social.reject', 'social', $id, $row, ['status' => 'rejected']);
        return $this->ok($m->find($id));
    }

    public function markPosted(int $id)
    {
        $m   = new SocialPostModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Social post not found.', 404);
        }
        $externalId = (string) ($this->input()['external_post_id'] ?? '');
        $m->update($id, [
            'status'           => 'posted',
            'published_at'     => date('Y-m-d H:i:s'),
            'external_post_id' => $externalId !== '' ? $externalId : null,
        ]);
        $this->audit('social.posted', 'social', $id, null, ['external_post_id' => $externalId]);
        return $this->ok($m->find($id));
    }

    private function normalize(array $body, bool $partial = false): array
    {
        $allowed = [
            'campaign_id', 'channel', 'content', 'media_refs', 'hashtags',
            'scheduled_at', 'status', 'bot_generated',
        ];
        $out = array_intersect_key($body, array_flip($allowed));
        foreach (['media_refs', 'hashtags'] as $jf) {
            if (isset($out[$jf]) && is_array($out[$jf])) {
                $out[$jf] = json_encode($out[$jf]);
            }
        }
        if (! $partial) {
            $out['channel'] ??= 'linkedin';
            $out['status']  ??= 'draft';
            $out['content'] ??= '';
        }
        return $out;
    }
}
