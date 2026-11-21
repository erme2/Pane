<?php

namespace Tests\Feature\Maps;

use App\Exceptions\Maps\InvalidMapException;
use App\Models\ApplicationRegistration;
use App\Models\AuditEvent;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\AppSettings;
use App\Support\PaneTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * App settings (Pane#158): declarations, defaults, edit rights, public exposure
 * through the maps bootstrap, and image upload validation against a fake disk.
 */
class AppSettingsApiTest extends TestCase
{
    private ApplicationRegistration $application;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.table_prefix' => 'pane_']);
        $this->clean();
        Storage::fake(AppSettings::DISK);

        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);
        $this->application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        User::query()->delete();
        $this->admin = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        app(AppSettings::class)->declare([
            ['key' => 'app.name', 'type' => 'text', 'default' => 'Sweegly', 'public' => true, 'label' => 'Name'],
            ['key' => 'app.beta', 'type' => 'flag', 'default' => false, 'public' => true],
            ['key' => 'limits.items', 'type' => 'number', 'default' => 50, 'public' => false],
            ['key' => 'app.logo', 'type' => 'image', 'public' => true, 'editable_by' => 'pane_admin'],
        ], 'test');
    }

    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    private function clean(): void
    {
        DB::table(PaneTable::name(PaneTable::MAPS_SETTINGS))->delete();
        DB::table(PaneTable::name(PaneTable::MAPS_SETTING_VALUES))->delete();
        MapVersion::query()->where('subject_type', AppSettings::SUBJECT)->delete();
    }

    public function test_declarations_are_versioned_and_replaced_as_a_whole_list(): void
    {
        $settings = app(AppSettings::class);
        $this->assertSame(['app.name', 'app.beta', 'limits.items', 'app.logo'], array_column($settings->declarations(), 'key'));

        $version = $settings->declare([['key' => 'app.name', 'type' => 'text', 'default' => 'New']], 'test');

        $this->assertSame(2, $version);
        $this->assertSame(['app.name'], array_column($settings->declarations(), 'key'));
        $history = MapVersion::query()->where('subject_type', AppSettings::SUBJECT)->orderBy('version')->get();
        $this->assertCount(2, $history);
        $this->assertSame(['app.beta', 'limits.items', 'app.logo'], $history[1]->diff['removed']);
        $this->assertSame(['app.name'], $history[1]->diff['changed']);
    }

    public function test_invalid_declarations_are_refused(): void
    {
        $this->expectExceptionMessage('settings.0.key');
        app(AppSettings::class)->declare([['key' => 'Bad Key', 'type' => 'text']], 'test');
    }

    public function test_declarations_check_type_default_and_editable_by(): void
    {
        try {
            app(AppSettings::class)->declare([
                ['key' => 'a', 'type' => 'colour'],
                ['key' => 'b', 'type' => 'flag', 'default' => 'yes'],
                ['key' => 'c', 'type' => 'text', 'editable_by' => 'everyone'],
                ['key' => 'c', 'type' => 'image', 'default' => 'x'],
            ], 'test');
            $this->fail('Expected an invalid declaration.');
        } catch (InvalidMapException $exception) {
            $this->assertEqualsCanonicalizing(
                ['settings.0.type', 'settings.1.default', 'settings.2.editable_by', 'settings.3.key', 'settings.3.default'],
                array_keys($exception->errors),
            );
        }
        $this->assertCount(4, app(AppSettings::class)->declarations());
    }

    public function test_public_settings_come_with_the_maps_bootstrap_with_defaults_until_a_value_is_set(): void
    {
        $user = $this->makePaneUser(2);

        $this->send($user, 'get', 'maps')->assertOk()
            ->assertJsonPath('data.attributes.settings.0', ['key' => 'app.name', 'type' => 'text', 'value' => 'Sweegly'])
            ->assertJsonPath('data.attributes.settings.1', ['key' => 'app.beta', 'type' => 'flag', 'value' => false])
            ->assertJsonPath('data.attributes.settings.2', ['key' => 'app.logo', 'type' => 'image', 'value' => null])
            ->assertJsonCount(3, 'data.attributes.settings');

        $this->setValue('app.name', 'Sweegly Beta', 0)->assertOk();

        $this->send($user, 'get', 'maps')
            ->assertJsonPath('data.attributes.settings.0.value', 'Sweegly Beta');
    }

    public function test_administrators_read_every_setting_and_users_cannot(): void
    {
        $this->send($this->admin, 'get', 'settings')->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.2.attributes.key', 'limits.items')
            ->assertJsonPath('data.2.attributes.value', 50)
            ->assertJsonPath('data.2.attributes.version', 0);

        $user = $this->makePaneUser(2);
        $this->send($user, 'get', 'settings')->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->send($user, 'get', 'settings/app.name')->assertForbidden();
        $this->send($user, 'put', 'settings/app.name', ['value' => 'x'], ['If-Match' => '"setting-app.name-0"'])->assertForbidden();
        $this->assertNull(DB::table(PaneTable::name(PaneTable::MAPS_SETTING_VALUES))->first());
    }

    public function test_a_value_is_set_with_if_match_and_audited_without_the_value(): void
    {
        $response = $this->setValue('limits.items', 80, 0)->assertOk()
            ->assertJsonPath('data.attributes.value', 80)
            ->assertJsonPath('data.attributes.version', 1)
            ->assertHeader('ETag', '"setting-limits.items-1"');

        $this->assertSame('80', (string) DB::table(PaneTable::name(PaneTable::MAPS_SETTING_VALUES))->where('key', 'limits.items')->value('value'));
        $event = AuditEvent::query()->where('action', 'settings.value.set')->get()
            ->first(fn (AuditEvent $event): bool => ($event->resource_ids['setting'] ?? null) === 'limits.items');
        $this->assertNotNull($event);
        $this->assertSame(['setting' => 'limits.items'], $event->resource_ids);
        $this->assertSame(['from_version' => 0, 'to_version' => 1], $event->metadata);
        $this->assertStringNotContainsString('80', json_encode($event->metadata));

        $this->setValue('limits.items', 90, 0)->assertStatus(412)->assertJsonPath('error.code', 'version_conflict');
        $this->setValue('limits.items', 90, 1)->assertOk()->assertJsonPath('data.attributes.version', 2);
        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }

    public function test_if_match_is_required_and_the_key_must_be_declared(): void
    {
        $this->send($this->admin, 'put', 'settings/app.name', ['value' => 'x'])->assertStatus(428);
        $this->send($this->admin, 'put', 'settings/app.name', ['value' => 'x'], ['If-Match' => 'bad'])->assertStatus(400);
        $this->send($this->admin, 'put', 'settings/nope', ['value' => 'x'], ['If-Match' => '"setting-nope-0"'])->assertNotFound();
        $this->send($this->admin, 'get', 'settings/nope')->assertNotFound();
    }

    public function test_values_are_validated_against_the_type(): void
    {
        $this->setValue('app.beta', 'yes', 0)->assertStatus(422)->assertJsonPath('error.fields.value', 'Must be true or false.');
        $this->setValue('app.name', 12, 0)->assertStatus(422);
        $this->setValue('app.name', str_repeat('a', 2001), 0)->assertStatus(422);
        $this->setValue('limits.items', '80', 0)->assertStatus(422);
        $this->setValue('app.logo', 'https://evil.test/x.png', 0)->assertStatus(422);
        $this->send($this->admin, 'put', 'settings/app.name', [], ['If-Match' => '"setting-app.name-0"'])->assertStatus(422);

        $this->setValue('app.beta', true, 0)->assertOk()->assertJsonPath('data.attributes.value', true);
        $this->setValue('app.logo', null, 0)->assertOk();
    }

    public function test_an_image_is_uploaded_to_the_r2_disk_and_its_metadata_becomes_the_value(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 40, 40);

        $response = $this->upload('app.logo', $file, 0)->assertOk()
            ->assertJsonPath('data.attributes.version', 1)
            ->assertJsonPath('data.attributes.value.mime', 'image/png')
            ->assertJsonPath('data.attributes.value.size', $file->getSize());

        $path = $response->json('data.attributes.value.path');
        $this->assertStringStartsWith('settings/app.logo/', $path);
        $this->assertStringEndsWith('.png', $path);
        Storage::disk(AppSettings::DISK)->assertExists($path);
        $this->assertSame(Storage::disk(AppSettings::DISK)->url($path), $response->json('data.attributes.value.url'));

        // A later upload keeps the old object.
        $second = $this->upload('app.logo', UploadedFile::fake()->image('b.jpg', 20, 20), 1)->assertOk()
            ->assertJsonPath('data.attributes.value.mime', 'image/jpeg');
        Storage::disk(AppSettings::DISK)->assertExists($path);
        Storage::disk(AppSettings::DISK)->assertExists($second->json('data.attributes.value.path'));

        $this->send($this->makePaneUser(2), 'get', 'maps')
            ->assertJsonPath('data.attributes.settings.2.value.path', $second->json('data.attributes.value.path'));
    }

    public function test_uploads_refuse_wrong_types_sizes_keys_and_versions(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->upload('app.logo', $svg, 0)->assertStatus(422)->assertJsonPath('error.fields.file', 'Must be a PNG, JPEG or WebP image.');

        $this->upload('app.logo', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), 0)->assertStatus(422);

        $big = UploadedFile::fake()->image('big.png', 10, 10)->size(AppSettings::MAX_IMAGE_BYTES / 1024 + 1);
        $this->upload('app.logo', $big, 0)->assertStatus(422);

        $this->upload('app.name', UploadedFile::fake()->image('a.png'), 0)->assertStatus(422)->assertJsonPath('error.fields.key', 'Only image settings accept an upload.');
        $this->upload('app.logo', UploadedFile::fake()->image('a.png'), 5)->assertStatus(412);
        $this->send($this->admin, 'post', 'settings/app.logo/upload', [], ['If-Match' => '"setting-app.logo-0"'])->assertStatus(422);

        $this->assertSame([], Storage::disk(AppSettings::DISK)->allFiles());
        $this->assertNull(DB::table(PaneTable::name(PaneTable::MAPS_SETTING_VALUES))->first());
    }

    public function test_users_cannot_upload(): void
    {
        $this->actingAs($this->makePaneUser(2));
        $this->upload('app.logo', UploadedFile::fake()->image('a.png'), 0, as: $this->makePaneUser(2))->assertForbidden();
        $this->assertSame([], Storage::disk(AppSettings::DISK)->allFiles());
    }

    public function test_settings_declare_command_reads_a_json_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'settings');
        file_put_contents($file, json_encode([['key' => 'site.title', 'type' => 'text', 'public' => true, 'default' => 'Hi']]));

        $this->artisan('settings:declare', ['file' => $file])->expectsOutput('Settings declared (version 2).')->assertSuccessful();
        $this->assertSame(['site.title'], array_column(app(AppSettings::class)->declarations(), 'key'));

        file_put_contents($file, '{not json');
        $this->artisan('settings:declare', ['file' => $file])->assertFailed();
        file_put_contents($file, json_encode([['key' => 'BAD', 'type' => 'text']]));
        $this->artisan('settings:declare', ['file' => $file])->assertFailed();
        $this->artisan('settings:declare', ['file' => $file.'-missing'])->assertFailed();
        $this->assertSame(['site.title'], array_column(app(AppSettings::class)->declarations(), 'key'));
        unlink($file);
    }

    private function setValue(string $key, mixed $value, int $version): TestResponse
    {
        return $this->send($this->admin, 'put', "settings/$key", ['value' => $value], ['If-Match' => "\"setting-$key-$version\""]);
    }

    private function upload(string $key, UploadedFile $file, int $version, ?User $as = null): TestResponse
    {
        $this->flushHeaders();
        $this->actingAs($as ?? $this->admin);

        return $this->withCsrfToken()->withV1ApplicationSession($this->application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', "\"setting-$key-$version\"")
            ->post("/api/v1/settings/$key/upload", ['file' => $file], ['Accept' => 'application/json']);
    }

    /** @param  array<string, mixed>  $body */
    private function send(User $as, string $method, string $path, array $body = [], array $headers = []): TestResponse
    {
        $this->flushHeaders();
        $this->actingAs($as);
        $request = $this->withCsrfToken()->withV1ApplicationSession($this->application)->withHeader('Origin', 'https://latte.test');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->json(strtoupper($method), "/api/v1/$path", $body);
    }
}
