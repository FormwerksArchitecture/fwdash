<?php

namespace App\Livewire\MonthEnd;

use App\Actions\Billing\ConfirmHourLinesAction;
use App\Actions\Billing\SetPeriodStatusAction;
use App\Billing\BillingEngine;
use App\Billing\PeriodReports;
use App\Billing\Spending;
use App\Enums\Permission;
use App\Livewire\Concerns\SortsRows;
use App\Models\Extra;
use App\Models\HourLine;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Where the month stands, for the partners: the old "Report for Jim". The
 * $95/hr line is the judgement, rows sort worst rate first and outstanding cash
 * is aged against the period end. Hours come from the hours table alone.
 * Spent is the Costs sheet total for the month, or failing that staff time at
 * cost (managers and admins), and Profit is the month's billing less it.
 *
 * A job's row opens in place before its page: the month's draws, where the
 * contract stands, and the month's hourly lines. Time logged on timesheets
 * against an extra waits there unconfirmed, billing nothing until confirmed.
 */
/**
 * @property-read array<string, mixed> $figures
 * @property-read Collection<int, array<string, mixed>> $rows
 * @property-read ?array<string, mixed> $detail
 */
#[Title('Month End')]
class MonthEndReport extends Component
{
    use SortsRows;

    #[Url]
    public ?string $period = null;

    #[Url]
    public string $only = 'billing';

    #[Url]
    public string $sort = 'rateLive';

    #[Url]
    public string $direction = 'asc';

    /** The job whose row is open. */
    public ?string $expanded = null;

    /** @var array<int, string> */
    protected array $textColumns = ['jobName', 'developer'];

    public function mount(BillingEngine $engine): void
    {
        if (! in_array($this->period, $engine->periods(), true)) {
            $this->period = $engine->latestPeriod();
        }

        if (! array_key_exists($this->only, PeriodReports::Filters)) {
            $this->only = 'billing';
        }
    }

    /** Open the month clicked on the revenue chart, when it has a billing period. */
    public function openMonth(int $index): void
    {
        $month = $this->figures['series']['months'][$index] ?? null;

        if ($month && $month['period']) {
            $this->period = $month['period'];
        }
    }

    public function toggleJob(string $jobNumber): void
    {
        $this->expanded = $this->expanded === $jobNumber ? null : $jobNumber;
    }

    /** @param  array<int, int>  $lineIds */
    public function confirmLines(array $lineIds, bool $confirmed, ConfirmHourLinesAction $confirmHourLines): void
    {
        $this->authorize(Permission::EditBilling->value);

        if (! $this->period || ! $this->expanded) {
            return;
        }

        // Only the open job's lines for this month, whatever ids come in.
        $lineIds = HourLine::whereKey($lineIds)
            ->where('job_number', $this->expanded)
            ->whereDate('period', $this->period)
            ->pluck('id')
            ->all();

        $confirmHourLines->execute($lineIds, $confirmed);

        // A confirmed line bills, so the month's figures move with it.
        app(BillingEngine::class)->load();
        unset($this->figures, $this->rows, $this->detail);
    }

    public function closePeriod(SetPeriodStatusAction $setPeriodStatus): void
    {
        $this->setStatus($setPeriodStatus, 'closed');
    }

    public function reopenPeriod(SetPeriodStatusAction $setPeriodStatus): void
    {
        $this->setStatus($setPeriodStatus, 'open');
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function figures(): array
    {
        if (! $this->period) {
            return [];
        }

        $figures = app(PeriodReports::class)->monthEnd($this->period, $this->only);

        $keys = array_column($figures['series']['months'], 'key');
        $costs = app(Spending::class)->monthCosts($keys, withStaff: auth()->user()->can(Permission::ManageStaffCosts->value));

        if (! array_filter(array_column($costs, 'basis'))) {
            return $figures;
        }

        $key = $keys[$figures['chart']['selected']];
        $cost = $costs[$key];

        $figures['chart']['spent'] = array_column($costs, 'total');
        $figures['spent'] = [
            'key' => $key,
            ...$cost,
            'profit' => $cost['total'] === null ? null : $figures['total'] - $cost['total'],
            'margin' => $cost['total'] !== null && $figures['total'] ? ($figures['total'] - $cost['total']) / $figures['total'] : null,
        ];

        return $figures;
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function rows(): Collection
    {
        return $this->sortRows($this->figures['rows'] ?? collect());
    }

    /** @return ?array<string, mixed> */
    #[Computed]
    public function detail(): ?array
    {
        $row = $this->expanded ? $this->rows->firstWhere('jobNumber', $this->expanded) : null;

        if (! $row) {
            return null;
        }

        $grid = app(BillingEngine::class)->periodGrid($this->period, $this->expanded);

        return [
            'row' => $row,
            'grid' => $grid,
            'draws' => collect($grid['phases'])->reject(fn (array $phase) => (float) $phase['billedThisMonth'] == 0)->values(),
            'lines' => collect($grid['hourLines']),
            'extras' => Extra::whereKey(array_filter(array_column($grid['hourLines'], 'extraId')))->get()->keyBy('id'),
        ];
    }

    protected function setStatus(SetPeriodStatusAction $setPeriodStatus, string $status): void
    {
        $this->authorize(Permission::EditBilling->value);

        if (! $this->period) {
            return;
        }

        $setPeriodStatus->execute($this->period, $status);

        app(BillingEngine::class)->load();
        unset($this->figures, $this->rows);
    }

    public function render(BillingEngine $engine): View
    {
        return view('livewire.month-end.report', [
            'periods' => array_reverse($engine->periods()),
            'engine' => $engine,
        ]);
    }
}
