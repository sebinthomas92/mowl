<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preview media for library prompts. `*_url` is where the licensed source hosts the preview;
 * `*_path` is the copy the app keeps on its media disk once it has been fetched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_prompts', function (Blueprint $table) {
            $table->string('video_url', 500)->nullable();
            $table->string('poster_url', 500)->nullable();
            $table->string('video_path', 300)->nullable();
            $table->string('poster_path', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('library_prompts', function (Blueprint $table) {
            $table->dropColumn(['video_url', 'poster_url', 'video_path', 'poster_path']);
        });
    }
};
