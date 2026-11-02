<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The profit a project is set to make, as a fraction of its fees (0.25 is
 * 25%), 20% unless someone sets another. Typed in when it is set up or on
 * its page; the workbook has no such column, so an upload keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('target_profit', 6, 4)->nullable()->default(0.2);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('target_profit');
        });
    }
};
