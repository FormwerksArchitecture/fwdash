<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * An hour line bills once it is confirmed: who and when. Lines already here
 * came from the workbook and billed as they stood, so they start confirmed.
 * Time logged on a timesheet against an extra arrives unconfirmed, which is
 * what timesheet_entries.extra_id is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hour_lines', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_by')->nullable();
        });

        DB::table('hour_lines')->update(['confirmed_at' => now(), 'confirmed_by' => 'Workbook upload']);

        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('extra_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropIndex(['extra_id']);
            $table->dropColumn('extra_id');
        });

        Schema::table('hour_lines', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'confirmed_by']);
        });
    }
};
