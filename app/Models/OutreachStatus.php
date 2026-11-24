<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who is working an outreach prospect, and how far the first email has got.
 *
 * @property string $prospect_key
 * @property ?int $claimed_by
 * @property ?Carbon $claimed_at
 * @property ?int $drafted_by
 * @property ?Carbon $drafted_at
 * @property ?int $sent_by
 * @property ?Carbon $sent_at
 * @property ?int $pursuit_id
 */
class OutreachStatus extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'drafted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function drafter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drafted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** 'sent', 'drafted', 'claimed' or 'open'. */
    public function stage(): string
    {
        return match (true) {
            $this->sent_at !== null => 'sent',
            $this->drafted_at !== null => 'drafted',
            $this->claimed_by !== null => 'claimed',
            default => 'open',
        };
    }
}
