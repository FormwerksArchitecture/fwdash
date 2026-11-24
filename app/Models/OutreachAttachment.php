<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PDF partners can attach to an outreach email.
 *
 * @property int $id
 * @property string $name
 * @property string $filename
 * @property int $size
 * @property int $chunks
 * @property bool $is_default
 * @property bool $complete
 */
class OutreachAttachment extends Model
{
    /** Bytes per uploaded chunk: well under Vercel's 4.5 MB request cap. */
    public const ChunkBytes = 2 * 1024 * 1024;

    /** Bigger than this and many mail servers bounce the email once it is encoded. */
    public const MaxBytes = 15 * 1024 * 1024;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'chunks' => 'integer',
            'is_default' => 'boolean',
            'complete' => 'boolean',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeReady(Builder $query): void
    {
        $query->where('complete', true)->orderByDesc('is_default')->orderBy('name');
    }

    /** @return HasMany<OutreachAttachmentChunk, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(OutreachAttachmentChunk::class)->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The whole file. */
    public function contents(): string
    {
        return $this->parts()->pluck('data')->map(fn (string $data) => base64_decode($data))->implode('');
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1024 * 1024
            ? number_format($this->size / 1024 / 1024, 1).' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
