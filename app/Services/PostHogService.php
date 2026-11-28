<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Deletes a person, their events and their recordings from PostHog (sweegly#49).
 * The distinct id the apps identify with is the person's user uuid. The private
 * key and host come from the environment and are never printed or logged.
 */
class PostHogService
{
    public function isConfigured(): bool
    {
        return filled(config('services.posthog.private_key')) && filled(config('services.posthog.host'));
    }

    /**
     * Safe to repeat: PostHog answers the same for a person that is already gone.
     *
     * @throws RequestException
     */
    public function deletePerson(string $distinctId): void
    {
        $host = rtrim((string) config('services.posthog.host'), '/');

        Http::withToken((string) config('services.posthog.private_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post($host.'/api/projects/@current/persons/bulk_delete/', [
                'distinct_ids' => [$distinctId],
                'delete_events' => true,
                'delete_recordings' => true,
            ])
            ->throw();
    }
}
