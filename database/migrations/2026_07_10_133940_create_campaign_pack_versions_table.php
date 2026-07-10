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
        Schema::create('campaign_pack_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_pack_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->json('content');
            $table->json('evidence')->nullable();
            $table->json('compliance_flags')->nullable();
            $table->string('generator')->default('mock');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_pack_versions');
    }
};
