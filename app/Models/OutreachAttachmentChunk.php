<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $outreach_attachment_id
 * @property int $position
 * @property string $data base64
 */
class OutreachAttachmentChunk extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
