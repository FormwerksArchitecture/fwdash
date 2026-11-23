<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $prospect_key
 * @property string $subject
 * @property string $body
 * @property ?string $note
 * @property ?string $direct_email
 */
class OutreachDraft extends Model
{
    protected $guarded = [];
}
