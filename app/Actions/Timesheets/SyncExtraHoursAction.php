<?php

namespace App\Actions\Timesheets;

use App\Billing\BillingEngine;
use App\Models\BillingPeriod;
use App\Models\Extra;
use App\Models\HourLine;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;

/*
 * Time logged against an extra becomes hourly billing: one line per person,
 * extra and month, holding that month's timesheet hours at the rate table's
 * rate. The line arrives unconfirmed and bills nothing until it is confirmed
 * on Month End. A change to the hours puts it back to unconfirmed. A closed
 * month is left as it was billed.
 */
class SyncExtraHoursAction
{
    /** How an hour line written from timesheets is known. */
    public const RateSource = 'timesheet';

    public function __construct(
        protected AuditLogger $audit,
        protected BillingEngine $engine,
    ) {}

    public function execute(User $user, Extra $extra, CarbonImmutable $day): void
    {
        $period = $day->endOfMonth()->toDateString();

        if (BillingPeriod::whereDate('period', $period)->where('status', 'closed')->exists()) {
            return;
        }

        $staff = $user->teamMember()?->name ?? $user->name;

        $hours = (float) TimesheetEntry::query()
            ->where('user_id', $user->id)
            ->where('extra_id', $extra->id)
            ->whereBetween('work_date', [$day->startOfMonth()->toDateString(), $period])
            ->sum('hours');

        $line = HourLine::query()
            ->whereDate('period', $period)
            ->where('job_number', $extra->job_number)
            ->where('extra_id', $extra->id)
            ->where('staff', $staff)
            ->where('rate_source', self::RateSource)
            ->first();

        if ($hours == 0) {
            if ($line) {
                $before = $line->snapshot();
                $line->delete();

                $this->audit->log('delete', 'hour_lines', $before['id'], before: $before);
            }

            return;
        }

        $hours = number_format($hours, 2, '.', '');

        if (! $line) {
            [$rate] = $this->engine->resolveRate($extra->job_number, $staff);

            $line = HourLine::create([
                'period' => $period,
                'job_number' => $extra->job_number,
                'staff' => $staff,
                'hours' => $hours,
                'rate' => $rate,
                'rate_source' => self::RateSource,
                'note' => $extra->label(),
                'extra_id' => $extra->id,
            ]);

            $this->audit->log('insert', 'hour_lines', $line->id, after: $line->snapshot());

            return;
        }

        if ((float) $line->hours == (float) $hours) {
            return;
        }

        $before = $line->snapshot();
        $line->update(['hours' => $hours, 'confirmed_at' => null, 'confirmed_by' => null]);

        $this->audit->log('update', 'hour_lines', $line->id, before: $before, after: $line->snapshot());
    }
}
