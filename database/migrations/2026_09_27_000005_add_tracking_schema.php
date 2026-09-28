<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('timezone', 64)->default('UTC'));
        Schema::create('habit_tracking_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->restrictOnDelete();
            $table->date('effective_date');
            $table->string('type', 20);
            $table->unsignedInteger('goal_value')->nullable();
            $table->string('goal_unit', 30)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['habit_id', 'effective_date'], 'tracking_version_date_unique');
        });
        Schema::create('habit_tracking_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_tracking_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_checkpoint_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 80);
            $table->unsignedSmallInteger('sort_order');
            $table->unique(['habit_tracking_version_id', 'sort_order'], 'tracking_checkpoint_order_unique');
        });
        Schema::create('habit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('habit_tracking_version_id')->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('status', 20);
            $table->unsignedInteger('value')->nullable();
            $table->text('note')->nullable();
            $table->string('not_applicable_reason', 500)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['habit_id', 'entry_date']);
            $table->index(['user_id', 'entry_date', 'habit_id'], 'entries_owner_date_index');
        });
        Schema::create('habit_checkpoint_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('habit_tracking_checkpoint_id')->constrained()->restrictOnDelete();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['habit_entry_id', 'habit_tracking_checkpoint_id'], 'entry_checkpoint_unique');
        });
        Schema::create('habit_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->restrictOnDelete();
            $table->string('reason', 10);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->index(['habit_id', 'reason', 'end_date', 'start_date'], 'suspensions_lookup_index');
        });
        DB::statement('ALTER TABLE habit_suspensions ADD CONSTRAINT suspension_dates_valid CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement('ALTER TABLE habit_tracking_versions ADD CONSTRAINT tracking_dates_valid CHECK (end_date IS NULL OR end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_checkpoint_entries');
        Schema::dropIfExists('habit_entries');
        Schema::dropIfExists('habit_suspensions');
        Schema::dropIfExists('habit_tracking_checkpoints');
        Schema::dropIfExists('habit_tracking_versions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }
};
