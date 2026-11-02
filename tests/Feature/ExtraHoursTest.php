<?php

use App\Actions\Billing\ConfirmHourLinesAction;
use App\Billing\BillingEngine;
use App\Enums\TimeCategory;
use App\Livewire\Timesheets\Timesheet;
use App\Models\BillingPeriod;
use App\Models\Extra;
use App\Models\HourLine;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\TimesheetEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * An admin's active extra on a project is a row staff can log time to, and
 * that time is the project's hourly billing for the month, once confirmed.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-28 10:00');

    Project::create(['job_number' => '#8016', 'job_name' => 'Seaton', 'active' => true]);
    Project::create(['job_number' => '#8020', 'job_name' => 'Highlands', 'active' => true]);
    BillingPeriod::create(['period' => '2026-10-31', 'status' => 'open']);

    DB::table('rate_titles')->insert(['title' => 'Architect', 'default_charge_out' => 150, 'seq' => 1]);
    DB::table('staff')->insert(['name' => 'Tetiana', 'rate_title' => 'Architect', 'charge_out' => 145, 'current' => true]);

    $this->user = User::factory()->staff()->create(['email' => 'tetiana@formwerks.ca']);
    TeamMember::updateOrCreate(['email' => 'tetiana@formwerks.ca'], ['name' => 'Tetiana', 'tier' => 'staff', 'active' => true]);

    $this->extra = Extra::create(['job_number' => '#8016', 'code' => '8016-02', 'title' => 'VE revisions', 'status' => 'active']);
    $this->row = TimesheetEntry::makeRowKey(TimeCategory::Project, '#8016', 'Hours', $this->extra->id);
});

it('offers a project\'s active extras on the timesheet, and only those', function () {
    Extra::create(['job_number' => '#8016', 'title' => 'Parapet', 'status' => 'proposed']);

    $picker = Livewire::actingAs($this->user)->test(Timesheet::class)->viewData('picker');

    expect($picker['extras'])->toBe(['#8016' => [['id' => $this->extra->id, 'name' => '8016-02: VE revisions']]]);
});

it('adds an extra as a row and labels it', function () {
    Livewire::actingAs($this->user)->test(Timesheet::class)
        ->set('newRow', ['category' => 'project', 'job_number' => '#8016', 'phase_code' => 'Hours', 'extra_id' => $this->extra->id])
        ->call('addRow')
        ->assertHasNoErrors()
        ->assertSet('extraRows', [$this->row])
        ->assertSee('8016-02: VE revisions');
});

it('turns a month of time on an extra into one unconfirmed hourly line that bills nothing yet', function () {
    Livewire::actingAs($this->user)->test(Timesheet::class)
        ->call('saveHours', $this->row, '2026-10-27', '3')
        ->call('saveHours', $this->row, '2026-10-28', '1.5')
        ->assertHasNoErrors();

    expect(HourLine::sole()->only(['job_number', 'staff', 'hours', 'rate', 'rate_source', 'note', 'extra_id', 'confirmed_at']))->toMatchArray([
        'job_number' => '#8016',
        'staff' => 'Tetiana',
        'rate' => '145.00',
        'rate_source' => 'timesheet',
        'note' => '8016-02: VE revisions',
        'extra_id' => $this->extra->id,
        'confirmed_at' => null,
    ])->and((float) HourLine::sole()->hours)->toBe(4.5)
        ->and(app(BillingEngine::class)->jobPeriodTotals('#8016', '2026-10-31')['hourlyBilled'])->toBe('0.00');
});

it('bills the line once it is confirmed, and asks again when the hours change', function () {
    $timesheet = Livewire::actingAs($this->user)->test(Timesheet::class)->call('saveHours', $this->row, '2026-10-27', '4');

    $this->actingAs(User::factory()->admin()->create());
    app(ConfirmHourLinesAction::class)->execute([HourLine::sole()->id], true);

    expect((new BillingEngine)->jobPeriodTotals('#8016', '2026-10-31')['hourlyBilled'])->toBe('580.00')
        ->and(DB::table('project_financials')->where('job_number', '#8016')->value('billed'))->toEqual(580);

    $this->actingAs($this->user);
    $timesheet->call('saveHours', $this->row, '2026-10-27', '5');

    expect(HourLine::sole()->confirmed_at)->toBeNull()
        ->and((new BillingEngine)->jobPeriodTotals('#8016', '2026-10-31')['hourlyBilled'])->toBe('0.00');

    $timesheet->call('saveHours', $this->row, '2026-10-27', '');
    expect(HourLine::count())->toBe(0);
});

it('refuses time on an extra from another project or one that is no longer active', function () {
    $elsewhere = TimesheetEntry::makeRowKey(TimeCategory::Project, '#8020', 'Hours', $this->extra->id);

    Livewire::actingAs($this->user)->test(Timesheet::class)
        ->call('saveHours', $elsewhere, '2026-10-27', '2')
        ->assertHasErrors('timesheet');

    $this->extra->update(['status' => 'complete']);

    Livewire::actingAs($this->user)->test(Timesheet::class)
        ->call('saveHours', $this->row, '2026-10-27', '2')
        ->assertHasErrors('timesheet');

    expect(TimesheetEntry::count())->toBe(0)->and(HourLine::count())->toBe(0);
});

it('leaves a closed month\'s billing as it was', function () {
    BillingPeriod::where('period', '2026-10-31')->update(['status' => 'closed']);

    Livewire::actingAs($this->user)->test(Timesheet::class)->call('saveHours', $this->row, '2026-10-27', '2');

    expect(TimesheetEntry::count())->toBe(1)->and(HourLine::count())->toBe(0);
});
