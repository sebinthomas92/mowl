<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->unsignedTinyInteger('processing_attempts')->default(0)->after('status');
            $table->timestamp('processing_started_at')->nullable()->after('processed_at');
            $table->unsignedInteger('processing_duration_ms')->nullable()->after('processing_started_at');
            $table->decimal('processing_cost', 10, 6)->default(0)->after('processing_duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn(['processing_attempts', 'processing_started_at', 'processing_duration_ms', 'processing_cost']);
        });
    }
};
