<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $period
 * @property string $job_number
 * @property ?string $staff
 * @property ?string $hours
 * @property ?string $rate
 * @property ?string $amount
 * @property ?string $rate_source
 * @property ?string $note
 * @property ?int $extra_id
 * @property ?string $confirmed_at
 * @property ?string $confirmed_by
 */
#[Fillable(['id', 'period', 'job_number', 'staff', 'hours', 'rate', 'amount', 'rate_source', 'note', 'extra_id', 'confirmed_at', 'confirmed_by'])]
class HourLine extends Model
{
    public $timestamps = false;

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            ...$this->only(['id', 'period', 'job_number', 'staff', 'hours', 'rate', 'amount', 'rate_source', 'note', 'extra_id', 'confirmed_at', 'confirmed_by']),
            'period' => substr((string) $this->period, 0, 10),
        ];
    }
}
