<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members and invitations can be limited to one brand. Null means every brand in the workspace.
 * Deleting the brand removes the scoped membership rather than widening it to every brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('role')->constrained()->cascadeOnDelete();
        });
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('role')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
