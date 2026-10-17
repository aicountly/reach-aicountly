<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Render;

use App\Libraries\Video\Automation\VideoProviders;

/**
 * Development/test renderer: every render is "done" on the first poll with an
 * obviously fake mock:// URL. Refuses in production.
 */
final class MockRenderClient implements RenderClient
{
    /** @var list<array<string, mixed>> edits submitted (tests inspect these) */
    public array $submitted = [];

    public function __construct(private readonly string $outcome = 'done')
    {
        VideoProviders::refuseMockInProduction('renderer');
    }

    public function name(): string
    {
        return 'mock_render';
    }

    public function submit(array $edit): string
    {
        $this->submitted[] = $edit;
        return 'mock-' . substr(hash('sha256', json_encode($edit) . microtime()), 0, 24);
    }

    public function status(string $renderId): RenderResult
    {
        return $this->outcome === 'failed'
            ? new RenderResult('failed', 'failed', error: 'mock render failure')
            : new RenderResult('done', 'done', "mock://render/{$renderId}.mp4", "mock://render/{$renderId}-poster.jpg", "mock://render/{$renderId}-thumb.jpg", null, 42.0);
    }
}
