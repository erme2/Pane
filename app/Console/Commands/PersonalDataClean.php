<?php

namespace App\Console\Commands;

use App\Exceptions\PersonalDataCleanupException;
use App\Models\User;
use App\Services\PersonalDataCleaner;
use App\Services\UserAnonymisationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PersonalDataClean extends Command
{
    protected $signature = 'personal-data:clean {uuid : The uuid of an anonymised user}';

    protected $description = "Clean the install's own data of an anonymised user, as the table maps' personal_data rules say. Safe to repeat.";

    public function handle(PersonalDataCleaner $cleaner, UserAnonymisationService $anonymisation): int
    {
        $uuid = strtolower((string) $this->argument('uuid'));
        $user = Str::isUuid($uuid) ? User::query()->where('uuid', $uuid)->first() : null;

        if (! $user instanceof User) {
            $this->error('No user has that uuid.');

            return self::FAILURE;
        }
        if (! $anonymisation->isAnonymised($user)) {
            $this->error('The user is not anonymised. Anonymise the user first (DELETE /api/v1/users/<user_id>).');

            return self::FAILURE;
        }

        try {
            $result = $cleaner->clean($user);
        } catch (PersonalDataCleanupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['tables'] as $table => $rows) {
            $this->line("$table: $rows row(s)");
        }
        $this->info('PostHog: '.$result['posthog'].($result['posthog'] === 'skipped' ? ' (POSTHOG_PRIVATE_KEY or POSTHOG_HOST is not set)' : ''));

        return self::SUCCESS;
    }
}
