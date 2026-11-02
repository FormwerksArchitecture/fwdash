<?php

namespace App\Actions\Billing;

use App\Models\HourLine;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/*
 * Confirms hourly billing lines on Month End, or takes a confirmation back.
 * Only a confirmed line bills, so this changes what a month bills: the month
 * must be open, and each job's stored financials are worked out again.
 */
class ConfirmHourLinesAction
{
    public function __construct(
        protected AuditLogger $audit,
        protected EnsurePeriodIsOpenAction $ensurePeriodIsOpen,
        protected RefreshProjectFinancialsAction $refreshFinancials,
    ) {}

    /** @param  array<int, int>  $lineIds */
    public function execute(array $lineIds, bool $confirmed): int
    {
        $changed = HourLine::whereKey($lineIds)
            ->when($confirmed, fn ($query) => $query->whereNull('confirmed_at'), fn ($query) => $query->whereNotNull('confirmed_at'))
            ->get(['id', 'period', 'job_number']);

        $changed->pluck('period')->map(fn ($period) => substr((string) $period, 0, 10))->unique()
            ->each(fn (string $period) => $this->ensurePeriodIsOpen->execute($period));

        $by = auth()->user()->name ?? 'system';

        DB::transaction(function () use ($changed, $confirmed, $by) {
            HourLine::whereKey($changed->modelKeys())->update($confirmed
                ? ['confirmed_at' => now(), 'confirmed_by' => $by]
                : ['confirmed_at' => null, 'confirmed_by' => null]);

            foreach ($changed as $line) {
                $this->audit->log($confirmed ? 'confirm' : 'unconfirm', 'hour_lines', $line->id, after: $confirmed ? ['confirmed_by' => $by] : null);
            }
        });

        $changed->pluck('job_number')->unique()->each(fn (string $jobNumber) => $this->refreshFinancials->execute($jobNumber));

        return $changed->count();
    }
}
