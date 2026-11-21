<?php

namespace App\Console\Commands;

use App\Exceptions\Maps\InvalidMapException;
use App\Services\AppSettings;
use Illuminate\Console\Command;
use JsonException;

class SettingsDeclare extends Command
{
    protected $signature = 'settings:declare {file : JSON file with a list of settings: key, type, default, public, editable_by, label, help}';

    protected $description = "Replace the install's app setting declarations with the list in a JSON file (a new version).";

    public function handle(AppSettings $settings): int
    {
        $file = (string) $this->argument('file');
        $json = is_file($file) ? file_get_contents($file) : false;
        if ($json === false) {
            $this->error("Cannot read $file.");

            return self::FAILURE;
        }

        try {
            $version = $settings->declare(json_decode($json, true, 512, JSON_THROW_ON_ERROR), 'settings:declare');
        } catch (JsonException $exception) {
            $this->error("$file is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        } catch (InvalidMapException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Settings declared (version $version).");

        return self::SUCCESS;
    }
}
