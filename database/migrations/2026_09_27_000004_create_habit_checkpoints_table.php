<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habit_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->index(['habit_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_checkpoints');
    }
};
