<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archived products drop out of the Products list but keep every fact, launch pack and approval.
 * They can be restored from the Archived filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'archived_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'archived_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
