<?php

namespace App\Libraries;

interface JobHandlerInterface
{
    /**
     * @param array<string,mixed> $payload  decoded payload_json (already secret-redacted for read paths)
     * @return array<string,mixed>          the result, stored on the job row as result_json
     * @throws \Throwable                    Handlers throw to signal a failure that should retry/dead-letter
     */
    public function handle(array $payload, JobContext $ctx): array;
}
