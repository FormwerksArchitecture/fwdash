<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outreach went back to copy and paste: partners open the email in Outlook and attach
     * the portfolio themselves, so the Outlook connection, the PDF library and the
     * "drafted" step are gone. The signature and the claim / sent tracking stay.
     */
    public function up(): void
    {
        Schema::dropIfExists('outreach_attachment_chunks');
        Schema::dropIfExists('outreach_attachments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['outlook_access_token', 'outlook_refresh_token', 'outlook_expires_at']);
        });

        Schema::table('outreach_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('drafted_by');
            $table->dropColumn('drafted_at');
        });
    }

    public function down(): void
    {
        Schema::table('outreach_statuses', function (Blueprint $table) {
            $table->foreignId('drafted_by')->nullable()->after('claimed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('drafted_at')->nullable()->after('drafted_by');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('outlook_access_token')->nullable();
            $table->text('outlook_refresh_token')->nullable();
            $table->timestamp('outlook_expires_at')->nullable();
        });

        Schema::create('outreach_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('filename');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('chunks');
            $table->boolean('is_default')->default(false);
            $table->boolean('complete')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outreach_attachment_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_attachment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->longText('data');
            $table->unique(['outreach_attachment_id', 'position']);
        });
    }
};
