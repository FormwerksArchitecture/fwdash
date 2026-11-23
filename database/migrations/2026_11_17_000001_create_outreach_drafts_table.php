<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ready-to-send first emails and any direct address found for the contact, kept apart from outreach_prospects so re-importing the research keeps them.
        Schema::create('outreach_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('prospect_key')->unique();
            $table->string('subject');
            $table->text('body');
            $table->text('note')->nullable();
            $table->string('direct_email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_drafts');
    }
};
