<?php

use App\Enums\PursuitStage;
use App\Livewire\BusinessDevelopment\OutreachIndex;
use App\Livewire\BusinessDevelopment\OutreachShow;
use App\Models\OutreachAttachment;
use App\Models\OutreachDraft;
use App\Models\OutreachProspect;
use App\Models\OutreachStatus;
use App\Models\Pursuit;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function outreachFile(): string
{
    $folder = sys_get_temp_dir().'/outreach-'.uniqid();
    mkdir("{$folder}/outreach-thumbs", recursive: true);
    file_put_contents("{$folder}/outreach-thumbs/abc.jpg", 'jpeg-bytes');

    $row = fn (string $key, string $name, int $score, string $tier, string $status, array $extra = []) => [
        'key' => $key, 'name' => $name, 'score' => $score, 'tier' => $tier, 'region' => 'Metro Vancouver', 'hq' => 'Burnaby',
        'person' => null, 'activity' => ['status' => $status, 'confirmed' => true], 'types' => ['Townhomes'], 'tags' => [],
        'sources' => ['https://example.com'], 'recent' => [], 'projects' => [], 'img' => '', 'hasContact' => false,
        'hasLinkedIn' => false, 'hasEmail' => false, ...$extra,
    ];

    file_put_contents("{$folder}/outreach.json", json_encode([
        'built' => '2026-10-06',
        'mapUrl' => null,
        'types' => [['label' => 'Townhomes', 'color' => '#b5562f']],
        'rows' => [
            $row('allaire', 'Allaire Living', 5, 'A', 'Active', ['img' => 'abc.jpg', 'tags' => ['In deck'], 'projects' => [['name' => 'Stone Ridge', 'where' => 'Port Moody', 'img' => 'abc.jpg', 'lat' => 49.2, 'lon' => -122.8]]]),
            $row('quietco', 'Quiet Co', 3, 'C', 'Quiet'),
            $row('skipped', 'Skipped Ltd', 4, 'B', 'Active', ['tags' => ['Skip']]),
        ],
    ]));

    return "{$folder}/outreach.json";
}

it('imports the outreach research with its thumbnails', function () {
    $this->artisan('outreach:import', ['path' => outreachFile()])->assertSuccessful();

    expect(OutreachProspect::count())->toBe(3);
    $this->assertDatabaseHas('outreach_projects', ['prospect_key' => 'allaire', 'name' => 'Stone Ridge']);
    $this->assertDatabaseHas('outreach_images', ['name' => 'abc.jpg']);
});

it('filters to tiers A and B by default and hides skipped rows', function () {
    $this->artisan('outreach:import', ['path' => outreachFile()]);

    $this->actingAs(User::factory()->manager()->create());

    $component = Livewire::test(OutreachIndex::class);

    expect($component->get('rows')->pluck('key')->all())->toBe(['allaire']);

    $component->call('toggle', 'tiers', 'C');

    expect($component->get('rows')->pluck('key')->all())->toBe(['allaire', 'quietco']);
});

it('shows a prospect and serves its image', function () {
    $this->artisan('outreach:import', ['path' => outreachFile()]);

    $this->actingAs(User::factory()->manager()->create());

    $this->get(route('outreach.show', 'allaire'))->assertOk()->assertSee('Stone Ridge');
    $this->get(route('outreach.images', 'abc.jpg'))->assertOk()->assertHeader('Cache-Control');
});

it('loads drafted emails and shows them on the prospect page, surviving a re-import', function () {
    $file = outreachFile();
    $this->artisan('outreach:import', ['path' => $file]);

    $drafts = sys_get_temp_dir().'/drafts-'.uniqid().'.json';
    file_put_contents($drafts, json_encode([['key' => 'allaire', 'subject' => 'Stone Ridge, and your next site', 'body' => "Hi Marc,\n\nHello there.", 'note' => 'Check first']]));

    $this->artisan('outreach:drafts', ['path' => $drafts])->assertSuccessful();
    $this->artisan('outreach:import', ['path' => $file]);

    $this->actingAs(User::factory()->manager()->create());

    $this->get(route('outreach.show', 'allaire'))->assertOk()
        ->assertSee('Drafted email')->assertSee('Stone Ridge, and your next site')->assertSee('Check first');
    $this->get(route('outreach.show', 'quietco'))->assertOk()->assertDontSee('Drafted email');
});

it('asks the office inbox to pass the email on until a direct address is saved', function () {
    $file = outreachFile();
    $this->artisan('outreach:import', ['path' => $file]);
    OutreachProspect::where('key', 'allaire')->update([
        'email' => 'hello@allaire.test',
        'person' => ['name' => 'Marc Allaire', 'email' => '', 'linkedin' => 'https://linkedin.com/in/marc'],
    ]);
    OutreachDraft::create(['prospect_key' => 'allaire', 'subject' => 'Stone Ridge, and your next site', 'body' => 'Hi Marc,']);

    $this->actingAs(User::factory()->manager()->create());

    $component = Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire']);

    expect($component->instance()->email())
        ->to->toBe('hello@allaire.test')
        ->subject->toBe('For Marc Allaire: Stone Ridge, and your next site')
        ->body->toStartWith('Could you please pass this along to Marc?')
        ->linkedinNote->toContain('your hello@ inbox')
        ->mailto->toBe('mailto:hello@allaire.test?subject=For%20Marc%20Allaire%3A%20Stone%20Ridge%2C%20and%20your%20next%20site&body=Could%20you%20please%20pass%20this%20along%20to%20Marc%3F%20Thank%20you.%0D%0A%0D%0AHi%20Marc%2C')
        ->outlook->toStartWith('https://outlook.office.com/mail/deeplink/compose?to=hello%40allaire.test&subject=For%20Marc%20Allaire%3A%20Stone%20Ridge')
        ->outlook->toContain('&body=Could%20you%20please%20pass%20this%20along%20to%20Marc%3F%20Thank%20you.%0A%0AHi%20Marc%2C');

    $this->get(route('outreach.show', 'allaire'))->assertSee('Open in Outlook');

    OutreachProspect::where('key', 'allaire')->update(['email' => null]);

    expect(Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])->instance()->email())
        ->to->toBeNull()
        ->mailto->toStartWith('mailto:?subject=For%20Marc%20Allaire');

    $this->get(route('outreach.show', 'allaire'))->assertSee('Open in Outlook')->assertSee('To line will be blank');

    $component->set('directEmail', 'not an email')->call('saveDirectEmail')->assertHasErrors('directEmail');

    $component->set('directEmail', ' marc@allaire.test ')->call('saveDirectEmail')->assertHasNoErrors();

    expect(Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])->instance()->email())
        ->to->toBe('marc@allaire.test')
        ->subject->toBe('Stone Ridge, and your next site')
        ->body->toBe('Hi Marc,')
        ->linkedinNote->toBeNull();
});

function outreachProspectWithDraft(): void
{
    test()->artisan('outreach:import', ['path' => outreachFile()]);
    OutreachProspect::where('key', 'allaire')->update(['email' => 'hello@allaire.test']);
    OutreachDraft::create(['prospect_key' => 'allaire', 'subject' => 'Stone Ridge, and your next site', 'body' => 'Hi Marc,']);
}

function outlookPartner(array $attributes = []): User
{
    return tap(User::factory()->manager()->create()->forceFill([
        'microsoft_id' => 'ms-partner',
        'outlook_access_token' => 'access-1',
        'outlook_refresh_token' => 'refresh-1',
        'outlook_expires_at' => now()->addHour(),
        ...$attributes,
    ]))->save();
}

function portfolio(int $bytes = 1000, bool $default = true): OutreachAttachment
{
    $attachment = OutreachAttachment::create(['name' => 'Portfolio', 'filename' => 'portfolio.pdf', 'size' => $bytes, 'chunks' => 1, 'complete' => true, 'is_default' => $default]);
    $attachment->parts()->create(['position' => 0, 'data' => base64_encode(str_pad('%PDF-1.7', $bytes, 'x'))]);

    return $attachment;
}

it('uploads a PDF in pieces and turns away anything that is not one', function () {
    $this->actingAs(User::factory()->manager()->create());

    $pdf = '%PDF-1.7 '.str_repeat('a', OutreachAttachment::ChunkBytes);
    $created = $this->postJson(route('outreach.attachments.store'), ['name' => 'Portfolio', 'filename' => 'Portfolio 2026.pdf', 'size' => strlen($pdf)])
        ->assertOk()
        ->assertJson(['chunks' => 2]);

    $id = $created->json('id');
    $this->call('PUT', "/outreach-attachments/{$id}/chunks/0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], substr($pdf, 0, OutreachAttachment::ChunkBytes))->assertOk();
    $this->call('PUT', "/outreach-attachments/{$id}/chunks/1", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], substr($pdf, OutreachAttachment::ChunkBytes))->assertOk();
    $this->postJson(route('outreach.attachments.complete', $id))->assertOk();

    expect(OutreachAttachment::find($id))->complete->toBeTrue()->is_default->toBeTrue()
        ->and(OutreachAttachment::find($id)->contents())->toBe($pdf);

    $fake = $this->postJson(route('outreach.attachments.store'), ['name' => 'Fake', 'filename' => 'fake.pdf', 'size' => 5])->json('id');
    $this->call('PUT', "/outreach-attachments/{$fake}/chunks/0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], 'hello');
    $this->postJson(route('outreach.attachments.complete', $fake))->assertUnprocessable();

    expect(OutreachAttachment::find($fake))->toBeNull();

    $this->postJson(route('outreach.attachments.store'), ['name' => 'Huge', 'filename' => 'huge.pdf', 'size' => OutreachAttachment::MaxBytes + 1])->assertJsonValidationErrors('size');
    $this->postJson(route('outreach.attachments.store'), ['name' => 'Doc', 'filename' => 'notes.docx', 'size' => 10])->assertJsonValidationErrors('filename');
});

it('keeps staff out of the PDF library uploads', function () {
    $this->actingAs(User::factory()->staff()->create());

    $this->postJson(route('outreach.attachments.store'), ['name' => 'Portfolio', 'filename' => 'p.pdf', 'size' => 10])->assertForbidden();
});

it('puts the email with its PDFs in the partner\'s Outlook drafts and claims the prospect', function () {
    outreachProspectWithDraft();
    $portfolio = portfolio();
    $partner = outlookPartner(['email_signature' => "Jim\nFormwerks"]);
    $this->actingAs($partner);

    Http::fake([
        'graph.microsoft.com/v1.0/me/messages' => Http::response(['id' => 'msg-1', 'webLink' => 'https://outlook.office.com/owa/?ItemID=msg-1']),
        'graph.microsoft.com/v1.0/me/messages/msg-1/attachments' => Http::response(['id' => 'att-1']),
    ]);

    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])
        ->assertSet('attachmentIds', [$portfolio->id])
        ->call('createDraft')
        ->assertSet('draftError', null)
        ->assertSet('draftLink', 'https://outlook.office.com/owa/?ItemID=msg-1');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.microsoft.com/v1.0/me/messages'
        && $request->hasHeader('Authorization', 'Bearer access-1')
        && $request['toRecipients'][0]['emailAddress']['address'] === 'hello@allaire.test'
        && $request['body']['content'] === "Hi Marc,\n\nJim\nFormwerks");
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/msg-1/attachments')
        && base64_decode($request['contentBytes']) === $portfolio->contents());

    expect(OutreachStatus::firstWhere('prospect_key', 'allaire'))
        ->claimed_by->toBe($partner->id)
        ->drafted_by->toBe($partner->id)
        ->sent_at->toBeNull();
});

it('sends a large PDF through an upload session in slices', function () {
    outreachProspectWithDraft();
    $portfolio = portfolio(4 * 1024 * 1024);
    $this->actingAs(outlookPartner());

    Http::fake([
        'graph.microsoft.com/v1.0/me/messages' => Http::response(['id' => 'msg-1', 'webLink' => 'https://outlook.office.com/x']),
        'graph.microsoft.com/v1.0/me/messages/msg-1/attachments/createUploadSession' => Http::response(['uploadUrl' => 'https://upload.outlook.test/session']),
        'upload.outlook.test/*' => Http::response([], 200),
    ]);

    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])->call('createDraft')->assertSet('draftError', null);

    $slices = Http::recorded(fn (Request $request) => $request->url() === 'https://upload.outlook.test/session')->values();

    expect($slices)->toHaveCount(2)
        ->and($slices[0][0]->header('Content-Range')[0])->toBe('bytes 0-3276799/4194304')
        ->and($slices[1][0]->header('Content-Range')[0])->toBe('bytes 3276800-4194303/4194304')
        ->and($slices[0][0]->hasHeader('Authorization'))->toBeFalse();
});

it('asks the partner to connect Outlook, and drops a connection Microsoft no longer accepts', function () {
    outreachProspectWithDraft();
    $this->actingAs(User::factory()->manager()->create());

    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])
        ->assertSee('Connect Outlook')
        ->call('createDraft')
        ->assertSet('draftError', 'Connect Outlook first, so the draft can go in your Drafts folder.');

    $partner = outlookPartner(['outlook_expires_at' => now()->subMinute()]);
    $this->actingAs($partner);
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])
        ->call('createDraft')
        ->assertSet('draftError', 'Connect Outlook first, so the draft can go in your Drafts folder.');

    expect($partner->fresh()->hasOutlook())->toBeFalse();
});

it('refreshes an expired Outlook token before creating the draft', function () {
    outreachProspectWithDraft();
    $partner = outlookPartner(['outlook_expires_at' => now()->subMinute()]);
    $this->actingAs($partner);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['id' => 'msg-1', 'webLink' => 'https://outlook.office.com/x']),
    ]);

    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])->call('createDraft')->assertSet('draftError', null);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com') && $request->hasHeader('Authorization', 'Bearer access-2'));
    expect($partner->fresh())->outlook_refresh_token->toBe('refresh-2');
});

it('marks a prospect sent by hand and puts the developer on the Pipeline once', function () {
    outreachProspectWithDraft();
    $this->actingAs(User::factory()->manager()->create(['name' => 'Nik Partner']));

    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])
        ->call('markSent')
        ->assertSee('Sent '.now()->format('M j, Y').' by Nik Partner')
        ->call('undoSent')
        ->assertSee('Mark as sent')
        ->call('markSent');

    expect(OutreachStatus::first()->sent_at)->not->toBeNull()
        ->and(Pursuit::count())->toBe(1)
        ->and(Pursuit::first())->developer->toBe('Allaire Living')->stage->toBe(PursuitStage::Contacted)->owner->toBe('Nik');
});

it('lets only the claimer or an admin release a claim, and filters the list by outreach progress', function () {
    outreachProspectWithDraft();
    $jim = User::factory()->manager()->create();
    $other = User::factory()->manager()->create();

    $this->actingAs($jim);
    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])->call('claim');

    expect(Livewire::test(OutreachIndex::class)->call('toggle', 'progress', 'mine')->get('rows')->pluck('key')->all())->toBe(['allaire']);

    $this->actingAs($other);
    expect(Livewire::test(OutreachIndex::class)->call('toggle', 'progress', 'mine')->get('rows'))->toBeEmpty()
        ->and(Livewire::test(OutreachIndex::class)->call('toggle', 'progress', 'claimed')->get('rows')->pluck('key')->all())->toBe(['allaire']);

    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])->call('release')->assertForbidden();

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])->call('release');

    expect(OutreachStatus::first()->claimed_by)->toBeNull();
});

it('signs the email with the partner\'s saved signature in every way of opening it', function () {
    outreachProspectWithDraft();
    $this->actingAs(User::factory()->manager()->create());

    $component = Livewire::test(OutreachShow::class, ['prospectKey' => 'allaire'])
        ->set('signature', "  Jim Partner\nFormwerks  ")
        ->call('saveSignature');

    expect($component->instance()->email())
        ->body->toBe("Hi Marc,\n\nJim Partner\nFormwerks")
        ->outlook->toStartWith('https://outlook.office.com/mail/deeplink/compose?')
        ->outlook->toContain('Jim%20Partner%0AFormwerks')
        ->mailto->toContain('Jim%20Partner%0D%0AFormwerks');
});
