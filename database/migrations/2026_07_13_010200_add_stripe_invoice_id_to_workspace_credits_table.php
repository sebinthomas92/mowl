<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_credits', function (Blueprint $table) {
            $table->string('stripe_invoice_id')->nullable()->unique()->after('campaign_generation_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_credits', function (Blueprint $table) {
            $table->dropUnique(['stripe_invoice_id']);
            $table->dropColumn('stripe_invoice_id');
        });
    }
};
