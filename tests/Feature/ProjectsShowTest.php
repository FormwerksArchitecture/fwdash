<?php

use App\Enums\Permission;
use App\Livewire\Projects\ProjectsShow;
use App\Models\MonthEntry;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('keeps the month note when a percentage is changed on the project page', function () {
    Project::create(['job_number' => '#8114', 'job_name' => 'Loftus 2']);
    MonthEntry::create(['period' => '2026-06-30', 'job_number' => '#8114', 'phase_code' => 'DD', 'pct_complete' => '0.2', 'note' => 'Drawings issued']);

    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(ProjectsShow::class, ['jobNumber' => '#8114'])
        ->call('saveEntry', '2026-06-30', 'DD', 'pct', '40');

    $entry = MonthEntry::sole();

    expect((float) $entry->pct_complete)->toBe(0.4)
        ->and($entry->note)->toBe('Drawings issued');
});

it('shows staff only their own projects', function () {
    Project::create(['job_number' => '#8114', 'job_name' => 'Loftus 2', 'active' => true]);
    Project::create(['job_number' => '#8115', 'job_name' => 'Loftus 3', 'active' => true]);
    TeamMember::create(['name' => 'PatTester', 'email' => 'pat.tester@formwerks.ca', 'tier' => 'staff']);
    ProjectAssignment::create(['job_number' => '#8114', 'member_name' => 'PatTester', 'role' => 'member', 'set_by' => 'test', 'set_at' => now()]);

    $this->actingAs(User::factory()->staff()->create(['email' => 'pat.tester@formwerks.ca']));

    $this->get(route('projects.index', ['show' => 'all']))->assertOk()->assertSee('Loftus 2')->assertDontSee('Loftus 3')->assertDontSee('See all');
    $this->get(route('projects.show', '#8114'))->assertOk();
    $this->get(route('projects.show', '#8115'))->assertForbidden();
});

it('starts managers on their own projects with a switch to see all, and always shows admins all', function () {
    Project::create(['job_number' => '#8114', 'job_name' => 'Loftus 2', 'active' => true]);
    Project::create(['job_number' => '#8115', 'job_name' => 'Loftus 3', 'active' => true]);
    TeamMember::create(['name' => 'PatTester', 'email' => 'pat.tester@formwerks.ca', 'tier' => 'pm']);
    ProjectAssignment::create(['job_number' => '#8114', 'member_name' => 'PatTester', 'role' => 'pm', 'set_by' => 'test', 'set_at' => now()]);

    $this->actingAs(User::factory()->manager()->create(['email' => 'pat.tester@formwerks.ca']));

    $this->get(route('projects.index'))->assertSee('See all')->assertSee('Loftus 2')->assertDontSee('Loftus 3');
    $this->get(route('projects.index', ['show' => 'all']))->assertSee('Loftus 2')->assertSee('Loftus 3');
    $this->get(route('projects.show', '#8115'))->assertOk();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('projects.index'))->assertSee('Loftus 2')->assertSee('Loftus 3')->assertDontSee('See all');
});

it('starts a project at a 20% target profit and takes another as a plain percentage', function () {
    Project::create(['job_number' => '#8114', 'job_name' => 'Loftus 2', 'active' => true]);
    DB::table('project_phase_fees')->insert(['job_number' => '#8114', 'phase_code' => 'SD', 'fee' => 40000]);

    expect(Project::find('#8114')->target_profit)->toBe('0.2000');

    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ProjectsShow::class, ['jobNumber' => '#8114'])
        ->assertSee('Forecast margin')
        ->assertSee('leaving $32,000 to spend')
        ->call('saveTargetProfit', '25%')
        ->assertHasNoErrors()
        ->assertSee('leaving $30,000 to spend')
        ->assertSee('Budget used')
        ->call('saveTargetProfit', '120')
        ->assertHasErrors('target_profit');

    expect(Project::find('#8114')->target_profit)->toBe('0.2500');
});

it('keeps the target profit box to people who can edit billing', function () {
    Project::create(['job_number' => '#8114', 'job_name' => 'Loftus 2', 'active' => true]);
    $viewer = User::factory()->staff()->create();
    $viewer->givePermissionTo(Permission::ViewFinancials->value);

    Livewire::actingAs($viewer)
        ->test(ProjectsShow::class, ['jobNumber' => '#8114'])
        ->call('saveTargetProfit', '30')
        ->assertForbidden();
});
