<?php

namespace Tests\Feature\Journals;

use App\Actions\Journals\DeleteJournal;
use App\Actions\Journals\SaveJournal;
use App\Models\Journal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 4)->setTime(12, 0));
    }

    private function editor(User $user, string $date = '2026-10-04')
    {
        return Livewire::actingAs($user)->withQueryParams(['date' => $date])->test('pages::journal.edit');
    }

    public function test_create_edit_clear_mood_reload_and_delete_with_confirmation(): void
    {
        $user = User::factory()->create();
        $page = $this->editor($user)->assertSee('No entry for this day yet.')->set('form.content', " 0\nSecond line ")->set('form.mood', '5')->call('save')->assertHasNoErrors()->assertSee('Journal saved.');
        $journal = $user->journals()->sole();
        $this->assertSame("0\nSecond line", $journal->content);
        $this->assertSame(5, $journal->mood);
        $this->assertSame('2026-10-04', $journal->journal_date->toDateString());
        $this->editor($user)->assertSet('form.content', "0\nSecond line")->assertSet('form.mood', 5);
        $page->set('form.content', ' 0 ')->set('form.mood', '')->call('save')->assertHasNoErrors();
        $this->assertNull($journal->fresh()->mood);
        $this->assertSame('0', $journal->fresh()->content);
        $this->assertSame(2, $journal->fresh()->revision);
        $page->call('confirmDeletion')->assertSet('showDeleteModal', true)->call('cancelDeletion')->assertSet('deletionRequested', false);
        $this->assertDatabaseCount('journals', 1);
        $page->call('confirmDeletion')->call('delete')->assertHasNoErrors()->assertSet('entryId', null)->assertSet('form.content', '')->assertSee('Journal and mood deleted.');
        $this->assertDatabaseCount('journals', 0);
        $page->set('form.content', 'New journal')->call('save')->assertHasNoErrors();
        $this->assertNotSame($journal->id, $user->journals()->sole()->id);
    }

    public function test_database_failure_preserves_draft_without_exposing_sql_bindings(): void
    {
        $user = User::factory()->create();
        Journal::creating(function () {
            throw new QueryException('mysql', 'insert into journals (content) values (?)', ['PRIVATE-SQL-CANARY'], new \PDOException('Controlled failure'));
        });
        try {
            $page = $this->editor($user)->set('form.content', 'Keep my draft')->call('save')
                ->assertHasErrors('form.content')->assertSet('form.content', 'Keep my draft')->assertDontSee('PRIVATE-SQL-CANARY');
            $this->assertDatabaseCount('journals', 0);
        } finally {
            Journal::flushEventListeners();
        }
    }

    public static function invalidInput(): array
    {
        return [
            ['content', ''], ['content', " \n\t "], ['content', "\u{00a0}"], ['content', []], ['content', str_repeat('字', 10001)],
            ['mood', 0], ['mood', 6], ['mood', -1], ['mood', 1.5], ['mood', true], ['mood', []], ['mood', 'no'], ['mood', '1e0'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_atomic_and_preserves_draft(string $field, mixed $value): void
    {
        $user = User::factory()->create();
        $journal = Journal::factory()->for($user)->create(['journal_date' => '2026-10-04', 'mood' => 4]);
        $before = $journal->fresh()->getAttributes();
        $this->editor($user)->set('form.content', 'Changed')->set('form.mood', 5)->set('form.'.$field, $value)->call('save')->assertHasErrors('form.'.$field)->assertSet('form.'.$field, $value);
        $this->assertSame($before, $journal->fresh()->getAttributes());
    }

    public function test_mood_only_rejected_text_limits_all_moods_and_escaping(): void
    {
        $user = User::factory()->create();
        $page = $this->editor($user)->set('form.mood', 5)->call('save')->assertHasErrors('form.content');
        $this->assertDatabaseCount('journals', 0);
        $page->set('form.content', str_repeat('字', 10000))->call('save')->assertHasNoErrors();
        $this->assertSame(10000, mb_strlen($user->journals()->sole()->content));
        foreach (range(1, 5) as $mood) {
            $page->set('form.mood', $mood)->call('save')->assertHasNoErrors();
            $this->assertSame($mood, $user->journals()->sole()->mood);
        }
        $page->set('form.content', '<script>alert("private")</script>')->call('save')->assertHasNoErrors();
        $this->actingAs($user)->get(route('journal.index'))->assertOk()->assertDontSee('<script>alert("private")</script>', false);
    }

    public function test_stale_create_edit_delete_and_recreated_identity_conflicts_preserve_drafts(): void
    {
        $user = User::factory()->create();
        $first = $this->editor($user)->set('form.content', 'First');
        $second = $this->editor($user)->set('form.content', 'Second');
        $first->call('save')->assertHasNoErrors();
        $second->call('save')->assertHasErrors('conflict')->assertSet('form.content', 'Second');
        $second->call('loadEntry')->set('form.content', 'Second saved')->call('save')->assertHasNoErrors();
        $first->call('confirmDeletion')->call('delete')->assertHasErrors('conflict')->assertSet('showDeleteModal', false);
        $first->call('save')->assertHasErrors('conflict');
        $first->call('loadEntry');
        $second->call('confirmDeletion')->call('delete')->assertHasNoErrors();
        $first->call('save')->assertHasErrors('conflict');
        $second->set('form.content', 'Replacement')->call('save')->assertHasNoErrors();
        $first->call('save')->assertHasErrors('conflict');
        $this->assertSame('Replacement', $user->journals()->sole()->content);
    }

    public static function invalidDates(): array
    {
        return [['2026-02-30'], ['2025-02-29'], ['2026-1-1'], ['0999-12-31'], ['10000-01-01'], ['today'], [''], [['2026-10-01']]];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_query_dates_never_create_rows(mixed $date): void
    {
        $this->actingAs(User::factory()->create())->get(route('journal.index', ['date' => $date]))->assertNotFound();
        $this->assertDatabaseCount('journals', 0);
    }

    public function test_historical_dates_and_navigation_boundaries(): void
    {
        $user = User::factory()->create();
        $this->editor($user, '1000-01-01')->assertDontSee('Previous day')->set('form.content', 'Before account creation')->call('save')->assertHasNoErrors();
        $this->editor($user, '2024-02-29')->set('form.content', 'Leap day')->call('save')->assertHasNoErrors();
        $page = $this->editor($user)->assertDontSee('Next day')->set('form.content', 'Draft');
        $page->set('selectedDate', '2026-02-30')->call('goToDate')->assertHasErrors('selectedDate')->assertSet('date', '2026-10-04')->assertSet('form.content', 'Draft');
        $page->set('selectedDate', '2026-10-05')->call('goToDate')->assertHasErrors('selectedDate');
        $page->set('selectedDate', '2024-02-29')->call('goToDate')->assertRedirect(route('journal.index', ['date' => '2024-02-29']));
        $this->get(route('journal.index', ['date' => '2026-10-05']))->assertNotFound();
        Journal::factory()->for($user)->create(['journal_date' => '9999-12-31']);
        $this->editor($user, '9999-12-31')->assertDontSee('Next day')->call('save')->assertHasErrors('date');
        $this->assertDatabaseCount('journals', 3);
        $this->assertDatabaseCount('habits', 0);
    }

    public function test_timezone_change_preserves_dates_and_blocks_stale_and_future_writes(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 4)->setTime(0, 30));
        $user = User::factory()->create(['timezone' => 'Pacific/Kiritimati']);
        $page = $this->editor($user)->set('form.content', 'Travel journal')->call('save')->assertHasNoErrors();
        $journal = $user->journals()->sole();
        $user->update(['timezone' => 'America/Los_Angeles']);
        $page->set('form.content', 'Stale timezone')->call('save')->assertHasErrors('conflict');
        $readonly = $this->editor($user)->assertSee('read-only')->assertSet('form.content', 'Travel journal');
        $readonly->call('save')->assertHasErrors('date');
        try {
            app(DeleteJournal::class)->handle($user, '2026-10-04', $journal->id, 1, $user->timezone);
            $this->fail('Future deletion must be denied.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }
        $this->assertSame('2026-10-04', $journal->fresh()->journal_date->toDateString());
        $this->travel(1)->days();
        $this->editor($user)->set('form.content', 'Editable again')->call('save')->assertHasNoErrors();
    }

    public function test_local_today_handles_offsets_and_dst_without_shifting_saved_dates(): void
    {
        foreach ([['Pacific/Kiritimati', '2026-10-05'], ['Pacific/Honolulu', '2026-10-04']] as [$timezone, $today]) {
            $user = User::factory()->create(['timezone' => $timezone]);
            Livewire::actingAs($user)->withQueryParams([])->test('pages::journal.edit')->assertSet('date', $today);
        }
        $user = User::factory()->create(['timezone' => 'America/New_York']);
        foreach (['2026-03-08 06:59:00', '2026-03-08 07:01:00', '2026-11-01 05:59:00', '2026-11-01 06:01:00'] as $instant) {
            $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));
            Livewire::actingAs($user)->withQueryParams([])->test('pages::journal.edit')->assertSet('date', substr($instant, 0, 10));
        }
    }

    public function test_policies_foreign_actions_and_unrelated_pages_preserve_privacy(): void
    {
        $owner = User::factory()->create(['is_private' => false]);
        $other = User::factory()->create();
        $journal = Journal::factory()->for($owner)->create(['content' => 'secret-journal-marker', 'mood' => 3]);
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability, $journal));
            $this->assertFalse(Gate::forUser($other)->allows($ability, $journal));
        }
        foreach (['content', 'mood'] as $field) {
            $this->assertArrayNotHasKey($field, $journal->toArray());
        }
        foreach ([$owner, $other] as $user) {
            foreach (['dashboard', 'tracking.index', 'habits.index', 'categories.index', 'settings.profile'] as $route) {
                $this->actingAs($user)->get(route($route))->assertOk()->assertDontSee('secret-journal-marker');
            }
        }
        $this->editor($other, '2026-10-01')->assertSet('form.content', '')->assertSet('form.mood', '')->assertDontSee('secret-journal-marker');
        foreach ([fn () => app(SaveJournal::class)->handle($other, '2026-10-01', ['content' => 'forged', 'user_id' => $owner->id], $journal->id, 1, 'UTC'), fn () => app(DeleteJournal::class)->handle($other, '2026-10-01', $journal->id, 1, 'UTC')] as $action) {
            try {
                $action();
                $this->fail('Foreign identity must be rejected.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('conflict', $e->errors());
            }
        }
        $this->assertSame('secret-journal-marker', $journal->fresh()->content);
        $created = app(SaveJournal::class)->handle($other, '2026-10-01', ['content' => 'Own', 'user_id' => $owner->id, 'revision' => 99], null, null, 'UTC');
        $this->assertSame($other->id, $created->user_id);
        $this->assertSame(1, $created->revision);
    }

    public static function lockedFields(): array
    {
        return [['ownerId', 123], ['date', '2026-10-03'], ['entryId', 123], ['entryRevision', 123], ['timezone', 'Asia/Manila'], ['deletionRequested', true]];
    }

    #[DataProvider('lockedFields')]
    public function test_locked_identity_cannot_be_tampered_with(string $field, mixed $value): void
    {
        $page = $this->editor(User::factory()->create());
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set($field, $value);
    }

    public function test_deletion_requires_server_confirmation(): void
    {
        $user = User::factory()->create();
        Journal::factory()->for($user)->create(['journal_date' => '2026-10-04']);
        $this->editor($user)->call('delete')->assertForbidden();
        $this->assertDatabaseCount('journals', 1);
    }

    public function test_guest_expired_session_and_changed_account_cannot_read_or_mutate(): void
    {
        $this->get(route('journal.index'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        Journal::factory()->for($user)->create(['journal_date' => '2026-10-04', 'content' => 'private-owner-content']);
        $response = $this->actingAs($user)->get(route('journal.index'))->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => ['form.content' => 'attack'], 'calls' => [['path' => '', 'method' => 'save', 'params' => []]]]]];
        Auth::logout();
        $this->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->withHeader('X-Livewire', 'true')->postJson(Livewire::getUpdateUri(), $payload)->assertForbidden()->assertDontSee('private-owner-content');
        $this->assertSame('private-owner-content', $user->journals()->sole()->content);
    }
}
