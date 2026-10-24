<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a workspace is for: "store" (sells its own products) or "agency" (manages brands for clients).
 * Agencies see client features: the client hub, client accounts and the client portal.
 *
 * Existing workspaces become agencies when they have client members or open client invitations, and stores
 * otherwise, however many brands they have. Owners can switch in Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspaces', 'kind')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->string('kind', 20)->default('agency');
            });
        }

        // Agency means outside clients are involved, not how many brands a workspace has.
        $agencyIds = DB::table('workspace_user')->where('role', 'client')->pluck('workspace_id')
            ->merge(Schema::hasTable('workspace_invitations') && Schema::hasColumn('workspace_invitations', 'role')
                ? DB::table('workspace_invitations')->where('role', 'client')->whereNull('accepted_at')->pluck('workspace_id') : collect())
            ->unique()->values()->all();

        DB::table('workspaces')->whereNotIn('id', $agencyIds)->update(['kind' => 'store']);
        DB::table('workspaces')->whereIn('id', $agencyIds)->update(['kind' => 'agency']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('workspaces', 'kind')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
};
