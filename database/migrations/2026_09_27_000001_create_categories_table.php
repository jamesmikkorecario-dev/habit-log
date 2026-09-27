<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->string('normalized_name', 180)->collation('utf8mb4_bin');
            $table->string('slug', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'normalized_name']);
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_owner_check CHECK ((is_system = 1 AND user_id IS NULL) OR (is_system = 0 AND user_id IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
