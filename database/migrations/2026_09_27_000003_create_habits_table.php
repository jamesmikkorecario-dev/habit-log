<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('type', 20);
            $table->string('visibility', 20)->default('private');
            $table->unsignedInteger('goal_value')->nullable();
            $table->string('goal_unit', 30)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'deleted_at', 'archived_at', 'id'], 'habits_owner_lifecycle_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habits');
    }
};
