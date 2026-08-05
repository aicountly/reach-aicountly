<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Gateways\Mailer;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\Media\MediaGalleryDeficitService;

/**
 * Daily gallery-deficit reminder to the superadmin: how many covers are
 * missing for the upcoming blog index, with the exact cover prompts to
 * generate (the superadmin produces ~20 images/day on personal AI accounts).
 * Sends nothing when there is no deficit.
 */
class GalleryDeficitAlertJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $report = (new MediaGalleryDeficitService())->report();

        if ($report['deficit'] <= 0) {
            return ['sent' => false, 'reason' => 'no_deficit'] + $report;
        }

        $to = trim((string) env('BLOG_GALLERY_ALERT_EMAIL', ''));
        if ($to === '') {
            return ['sent' => false, 'reason' => 'BLOG_GALLERY_ALERT_EMAIL_not_set'] + $report;
        }

        $lines = [
            "Cover-image gallery deficit: {$report['deficit']} image(s) needed",
            "Upcoming entries (next {$report['lookahead_days']} days): {$report['needed']} | Active gallery covers available: {$report['available']}",
            '',
            'Prompts to generate:',
        ];
        foreach ($report['upcoming'] as $i => $entry) {
            $lines[] = ($i + 1) . '. [' . ($entry['target_date'] ?: 'no date') . '] ' . $entry['title'];
            if ($entry['cover_prompt'] !== '') {
                $lines[] = '   Prompt: ' . $entry['cover_prompt'];
            }
        }
        $lines[] = '';
        $lines[] = 'Upload at: Reach console → Quality Centre → Cover Gallery';

        $body = implode("\n", $lines);
        $sent = Mailer::send($to, "[Reach] Cover gallery deficit: {$report['deficit']} image(s) needed", nl2br(htmlspecialchars($body)), $body);

        return ['sent' => $sent, 'to' => $to] + $report;
    }
}
