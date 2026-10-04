<?php

namespace Tests\Feature\Journals;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidRows(): array
    {
        return [[['mood' => 0]], [['mood' => 6]], [['user_id' => 999999999]], [['content' => null]]];
    }

    #[DataProvider('invalidRows')]
    public function test_database_rejects_invalid_rows(array $changes): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('journals')->insert($changes + ['user_id' => $user->id, 'journal_date' => '2026-10-04', 'content' => 'Valid']);
    }

    public function test_one_row_per_owner_date_with_default_revision_and_restricted_owner_deletion(): void
    {
        $user = User::factory()->create();
        DB::table('journals')->insert(['user_id' => $user->id, 'journal_date' => '2026-10-04', 'content' => 'First']);
        $this->assertSame(1, $user->journals()->sole()->revision);
        $this->assertNull($user->journals()->sole()->mood);
        Journal::factory()->create(['journal_date' => '2026-10-04']);
        try {
            Journal::factory()->for($user)->create(['journal_date' => '2026-10-04']);
            $this->fail('Expected unique constraint.');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
        }
        try {
            $user->delete();
            $this->fail('Expected restricted deletion.');
        } catch (QueryException $e) {
            $this->assertSame(1451, $e->errorInfo[1]);
        }
        $this->assertDatabaseCount('journals', 2);
    }
}
