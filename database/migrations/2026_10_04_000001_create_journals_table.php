<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('journal_date');
            $table->text('content');
            $table->unsignedTinyInteger('mood')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'journal_date']);
        });
        DB::statement('ALTER TABLE journals ADD CONSTRAINT journals_mood_check CHECK (mood IS NULL OR mood BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
