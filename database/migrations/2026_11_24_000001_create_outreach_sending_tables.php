<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A partner's own Outlook connection, so the dashboard can put a drafted email (with PDFs) in their Drafts folder. Tokens are stored encrypted.
        Schema::table('users', function (Blueprint $table) {
            $table->text('outlook_access_token')->nullable();
            $table->text('outlook_refresh_token')->nullable();
            $table->timestamp('outlook_expires_at')->nullable();
            $table->text('email_signature')->nullable();
        });

        // PDFs partners can attach. Kept in the database in base64 chunks: Vercel has no lasting disk and caps a request at 4.5 MB.
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

        // Who is working a prospect and how far the first email got. Apart from outreach_prospects so a re-import keeps it.
        Schema::create('outreach_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('prospect_key')->unique();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('drafted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('drafted_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('pursuit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_statuses');
        Schema::dropIfExists('outreach_attachment_chunks');
        Schema::dropIfExists('outreach_attachments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['outlook_access_token', 'outlook_refresh_token', 'outlook_expires_at', 'email_signature']);
        });
    }
};
