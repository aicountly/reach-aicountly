<?php

declare(strict_types=1);

namespace App\Libraries\Video\Providers;

interface RenderProviderInterface
{
    /**
     * Submit a render job to the provider.
     *
     * @param array{
     *   render_job_uuid: string,
     *   project_uuid: string,
     *   script_version_uuid: string,
     *   render_profile: array,
     *   asset_urls: array,
     *   idempotency_key: string,
     * } $job
     *
     * @throws ProviderNotConfiguredException
     * @throws ProviderRateLimitException
     * @throws ProviderInvalidRequestException
     * @throws ProviderTransientException
     */
    public function queue(array $job): RenderReceipt;

    /**
     * Query the current status of a provider job.
     *
     * @throws ProviderTransientException
     */
    public function status(string $providerJobId): RenderStatus;

    /**
     * Request cancellation of a queued or rendering job.
     * Returns false if the job was already in a terminal state.
     */
    public function cancel(string $providerJobId): bool;

    /**
     * Returns an associative array describing provider capabilities.
     *
     * @return array{
     *   max_resolution: string,
     *   supported_formats: string[],
     *   max_duration_secs: int,
     *   max_asset_bytes: int,
     *   supports_callback: bool,
     *   supports_polling: bool,
     * }
     */
    public function getCapabilities(): array;
}
