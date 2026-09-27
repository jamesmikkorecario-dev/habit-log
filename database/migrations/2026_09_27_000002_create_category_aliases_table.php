<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 60);
            $table->string('normalized_alias', 180)->collation('utf8mb4_bin');
            $table->unique(['category_id', 'normalized_alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_aliases');
    }
};
