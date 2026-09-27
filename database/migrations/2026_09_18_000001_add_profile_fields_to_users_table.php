<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('email');
            $table->string('display_name', 50)->nullable()->after('username');
            $table->string('bio', 160)->nullable()->after('display_name');
            $table->boolean('is_private')->default(true)->after('bio');
            $table->string('theme_key', 30)->default('terminal-green')->after('is_private');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'display_name', 'bio', 'is_private', 'theme_key']);
        });
    }
};
