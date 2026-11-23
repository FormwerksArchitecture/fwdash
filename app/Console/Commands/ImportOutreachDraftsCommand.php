<?php

namespace App\Console\Commands;

use App\Models\OutreachDraft;
use Illuminate\Console\Command;

/*
 * Loads the drafted first emails (database/data/outreach-drafts.json by default).
 * Each draft is matched to a prospect by key and replaces any earlier draft for it.
 */
class ImportOutreachDraftsCommand extends Command
{
    protected $signature = 'outreach:drafts {path? : Drafts JSON file}';

    protected $description = 'Load the drafted outreach emails shown on prospect pages';

    public function handle(): int
    {
        $path = (string) ($this->argument('path') ?: database_path('data/outreach-drafts.json'));
        $drafts = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($drafts) || ! array_is_list($drafts)) {
            $this->error("`{$path}` is not a drafts file.");

            return self::FAILURE;
        }

        foreach ($drafts as $draft) {
            OutreachDraft::updateOrCreate(
                ['prospect_key' => $draft['key']],
                ['subject' => $draft['subject'], 'body' => $draft['body'], 'note' => $draft['note'] ?? null],
            );
        }

        $this->comment('Loaded '.count($drafts).' drafts.');

        return self::SUCCESS;
    }
}
