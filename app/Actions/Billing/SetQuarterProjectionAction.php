<?php

namespace App\Actions\Billing;

use App\Models\MonthlyProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Sets a quarter's projected billing, split evenly over its three months. The
 * last month takes the odd cent so the months add back to the total. Clearing
 * it clears each month's projected billing.
 */
class SetQuarterProjectionAction
{
    public function __construct(protected SetProjectionAction $setProjection) {}

    public function execute(int $year, int $quarter, ?string $typed): void
    {
        if ($quarter < 1 || $quarter > 4) {
            throw ValidationException::withMessages(['projection' => __('Unknown quarter Q:quarter.', ['quarter' => $quarter])]);
        }

        $value = str_replace([',', '$', ' '], '', trim((string) $typed));

        if ($value !== '' && (! is_numeric($value) || (float) $value < 0)) {
            throw ValidationException::withMessages(['projection' => __('A projection must be a number, zero or more.')]);
        }

        $months = array_map(fn (int $month) => sprintf('%d-%02d', $year, $month), range($quarter * 3 - 2, $quarter * 3));

        DB::transaction(function () use ($months, $value) {
            if ($value === '') {
                MonthlyProjection::whereIn('month', $months)->whereNotNull('billed')->pluck('month')
                    ->each(fn (string $month) => $this->setProjection->execute($month, 'billed', null));

                return;
            }

            $cents = (int) round((float) $value * 100);
            $share = intdiv($cents, 3);

            foreach ($months as $index => $month) {
                $amount = $index === 2 ? $cents - $share * 2 : $share;
                $this->setProjection->execute($month, 'billed', number_format($amount / 100, 2, '.', ''));
            }
        });
    }
}
