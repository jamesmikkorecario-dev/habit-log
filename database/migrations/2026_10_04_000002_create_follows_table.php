<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('followed_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 10);
            $table->timestamp('requested_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['follower_id', 'followed_id']);
            $table->index(['followed_id', 'status', 'id']);
            $table->index(['follower_id', 'status', 'id']);
        });
        DB::statement("ALTER TABLE follows ADD CONSTRAINT follows_not_self CHECK (follower_id <> followed_id), ADD CONSTRAINT follows_state CHECK ((status = 'pending' AND accepted_at IS NULL) OR (status = 'accepted' AND accepted_at IS NOT NULL AND accepted_at >= requested_at))");
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
