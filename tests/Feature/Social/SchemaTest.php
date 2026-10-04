<?php

namespace Tests\Feature\Social;

use App\Models\Follow;
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
        return [[['status' => 'invalid']], [['status' => 'accepted']], [['accepted_at' => '2026-10-04 01:00:00']], [['status' => 'accepted', 'accepted_at' => '2026-10-03 00:00:00']], [['follower_id' => 99999999]], [['followed_id' => 99999999]], [['requested_at' => null]]];
    }

    #[DataProvider('invalidRows')]
    public function test_constraints_reject_invalid_rows(array $changes): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();
        $this->expectException(QueryException::class);
        DB::table('follows')->insert($changes + ['follower_id' => $a->id, 'followed_id' => $b->id, 'status' => 'pending', 'requested_at' => '2026-10-04 00:00:00', 'accepted_at' => null]);
    }

    public function test_no_self_follow_unique_direction_and_restricted_parent_deletion(): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();
        Follow::factory()->create(['follower_id' => $a->id, 'followed_id' => $b->id]);
        Follow::factory()->create(['follower_id' => $b->id, 'followed_id' => $a->id]);
        foreach ([fn () => Follow::factory()->create(['follower_id' => $a->id, 'followed_id' => $a->id]), fn () => Follow::factory()->create(['follower_id' => $a->id, 'followed_id' => $b->id]), fn () => $a->delete()] as $invalid) {
            try {
                $invalid();
                $this->fail('Constraint must reject write.');
            } catch (QueryException) {
                $this->assertDatabaseCount('follows', 2);
            }
        }
    }
}
