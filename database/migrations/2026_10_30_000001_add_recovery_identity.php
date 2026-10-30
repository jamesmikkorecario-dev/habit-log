<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Apply with writers stopped before enabling managed backups/deletions.
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('recovery_uuid')->nullable()->unique();
        });
        DB::table('users')->select('id')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['recovery_uuid' => (string) Str::uuid()]);
            }
        });
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('recovery_uuid')->nullable(false)->change();
        });
        Schema::create('recovery_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('ledger_id');
            $table->uuid('generation');
            $table->uuid('backup_id')->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('revision');
        });
    }

    public function down(): void
    {
        // Only revert in isolation after disabling recovery and retiring backups.
        Schema::dropIfExists('recovery_state');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('recovery_uuid');
        });
    }
};
