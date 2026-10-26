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
        Schema::table('sessions', function (Blueprint $table) {
            $table->uuid('management_id')->nullable()->unique();
            $table->char('origin_hash', 64)->nullable();
        });
        // Never read or rewrite authenticated payloads during upgrade.
        DB::table('sessions')->select('id')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('sessions')->where('id', $row->id)->update([
                    'management_id' => (string) Str::uuid(), 'origin_hash' => hash('sha256', $row->id),
                ]);
            }
        });
        Schema::create('session_revocations', function (Blueprint $table) {
            $table->char('session_hash', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_revocations');
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropUnique(['management_id']);
            $table->dropColumn(['management_id', 'origin_hash']);
        });
    }
};
