<?php

namespace Tests\Feature\crud;

use App\Models\AuditEvent;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\MapStore;
use App\Services\TextScreener;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Text screening of user-written shared fields (Pane#175): fields a map marks
 * with the "screened" validation are checked before the row is saved.
 */
class TextScreeningTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tsc_notes');
        Schema::create('tsc_notes', function ($table): void {
            $table->increments('note_id');
            $table->string('title');
            $table->string('body')->nullable();
        });

        $this->clearAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install'], 'test');
        $store->saveTable('install', [
            'name' => 'notes', 'sql_name' => 'tsc_notes',
            'fields' => [
                ['name' => 'note_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'string', 'nullable' => true, 'validations' => [['rule' => 'screened']]],
            ],
        ], 'test');

        $this->user = $this->makePaneUser(1);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tsc_notes');
        $this->clearAppMaps();

        parent::tearDown();
    }

    public function test_allowed_text_is_saved(): void
    {
        $this->withCsrfToken()->postJson('/crud/notes', ['title' => 'Milk', 'body' => 'Semi skimmed, 2 litres. Pass the class, please.'])
            ->assertStatus(Response::HTTP_CREATED);

        $this->assertSame(1, DB::table('tsc_notes')->count());
    }

    public function test_a_blocked_term_is_refused_naming_the_field_without_echoing_it(): void
    {
        $response = $this->withCsrfToken()->postJson('/crud/notes', ['title' => 'Milk', 'body' => 'you absolute f u c k']);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertStringNotContainsString('fuck', strtolower($response->getContent()));
        $this->assertStringContainsString('body', $response->getContent());
        $response->assertJsonPath('data.code', 'text_rejected')->assertJsonPath('data.field', 'body');
        $this->assertSame(0, DB::table('tsc_notes')->count());
    }

    public function test_an_update_is_screened_too(): void
    {
        $this->withCsrfToken()->postJson('/crud/notes', ['title' => 'Milk', 'body' => 'fine'])->assertStatus(Response::HTTP_CREATED);
        $id = (int) DB::table('tsc_notes')->value('note_id');

        $this->withCsrfToken()->putJson("/crud/notes/$id", ['title' => 'Milk', 'body' => 'sh1t'])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame('fine', DB::table('tsc_notes')->value('body'));
    }

    public function test_unmarked_fields_are_never_screened(): void
    {
        $this->withCsrfToken()->postJson('/crud/notes', ['title' => 'http://example.com fuck', 'body' => 'ok'])
            ->assertStatus(Response::HTTP_CREATED);
    }

    public function test_a_rejection_is_logged_without_the_text(): void
    {
        $this->withCsrfToken()->postJson('/crud/notes', ['title' => 'Milk', 'body' => 'https://spam.example.com/win'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $event = AuditEvent::query()->where('action', 'data.text.screened')->where('effective_user_id', $this->user->getKey())->firstOrFail();
        $this->assertSame('notes', $event->table_name);
        $this->assertSame(['body'], $event->changed_columns);
        $this->assertSame($this->user->getKey(), $event->effective_user_id);
        $this->assertStringNotContainsString('spam.example', (string) json_encode($event->toArray()));
    }

    public function test_the_screener_rules(): void
    {
        $screener = new TextScreener;

        $this->assertNull($screener->screen('A perfectly nice shopping list: bread, eggs & tea'));
        $this->assertNull($screener->screen('Scunthorpe classic, a bass guitar, Cockburn street'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('FUCK'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('f.u.c.k'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('fuuuuck'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('$h1t'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('please KILL   yourself'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('fuck!'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('shit!!'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('f.u.c.k!'));
        $this->assertSame(TextScreener::REASON_REPEAT, $screener->screen('aaaaaaaaaaaaaa'));
        $this->assertNull($screener->screen(str_repeat('ab ', 666).'ab'));
        $this->assertSame(TextScreener::REASON_LENGTH, $screener->screen(str_repeat('ab ', 667)));
        $this->assertSame(TextScreener::REASON_LINK, $screener->screen('see www.example.org'));
        $this->assertSame(TextScreener::REASON_LINK, $screener->screen('see https://example.org/x'));
    }

    public function test_punctuation_after_a_blocked_term_is_still_refused_on_create(): void
    {
        $this->withCsrfToken()->postJson('/crud/notes', ['title' => 'Milk', 'body' => 'fuck!'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame(0, DB::table('tsc_notes')->count());
    }

    public function test_the_text_length_limit_applies_on_create_and_update(): void
    {
        config(['pane.screening.max_length' => 20]);
        $this->withCsrfToken()->postJson('/crud/notes', ['title' => 'Milk', 'body' => str_repeat('a b ', 5)])
            ->assertStatus(Response::HTTP_CREATED);
        $id = (int) DB::table('tsc_notes')->value('note_id');

        $this->withCsrfToken()->postJson('/crud/notes', ['title' => 'Milk', 'body' => str_repeat('a b ', 5).'c'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->withCsrfToken()->putJson("/crud/notes/$id", ['title' => 'Milk', 'body' => str_repeat('a b ', 5).'c'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame(1, DB::table('tsc_notes')->count());
    }

    public function test_links_can_be_allow_listed_and_terms_added_per_install(): void
    {
        config([
            'pane.screening.links' => 'allow_list',
            'pane.screening.allowed_link_hosts' => ['sweegly.com'],
            'pane.screening.blocklist' => ['pineapple pizza'],
        ]);
        $screener = new TextScreener;

        $this->assertNull($screener->screen('go to https://www.sweegly.com/help'));
        $this->assertSame(TextScreener::REASON_LINK, $screener->screen('go to https://evil.example.com'));
        $this->assertSame(TextScreener::REASON_TERM, $screener->screen('I like Pineapple  Pizza'));

        config(['pane.screening.use_default_blocklist' => false, 'pane.screening.links' => 'off']);
        $this->assertNull((new TextScreener)->screen('fuck https://example.com'));
    }

    private function clearAppMaps(): void
    {
        foreach (MapTable::query()->get() as $table) {
            if (! $table->isSystem()) {
                MapVersion::query()->where('subject_type', 'table')->where('subject_id', $table->map_table_id)->delete();
                $table->fields()->get()->each(fn (MapField $field) => $field->delete());
                $table->delete();
            }
        }
    }
}
