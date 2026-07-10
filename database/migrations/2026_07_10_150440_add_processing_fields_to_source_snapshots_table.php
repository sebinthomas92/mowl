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
        Schema::table('source_snapshots', function (Blueprint $table) {
            $table->string('title')->nullable()->after('url');
            $table->text('canonical_url')->nullable()->after('title');
            $table->longText('extracted_content')->nullable()->after('status');
            $table->text('error_message')->nullable()->after('extracted_truth');
            $table->timestamp('fetched_at')->nullable()->after('error_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('source_snapshots', function (Blueprint $table) {
            $table->dropColumn(['title', 'canonical_url', 'extracted_content', 'error_message', 'fetched_at']);
        });
    }
};
