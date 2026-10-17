<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\Render;

interface RenderClient
{
    /**
     * Submit an edit (timeline + output) and return the provider's render id.
     *
     * @param array<string, mixed> $edit
     * @throws \App\Libraries\Video\Automation\VideoProviderException
     */
    public function submit(array $edit): string;

    /** @throws \App\Libraries\Video\Automation\VideoProviderException */
    public function status(string $renderId): RenderResult;

    public function name(): string;
}
