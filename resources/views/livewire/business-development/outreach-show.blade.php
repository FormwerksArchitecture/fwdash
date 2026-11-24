@php
    use App\Livewire\BusinessDevelopment\OutreachShow;

    $p = $prospect;
    $a = $p->activity;
    $person = $p->person;
    $tagLabels = ['In deck' => __('In regional deck'), 'Check first' => __('Possible client'), 'Warm lead' => __('Worked with us')];
    $short = fn (string $url) => preg_replace('#^https?://(www\.)?#', '', $url);
    $link = function (?string $url, ?string $label = null) use ($short) {
        $safe = OutreachShow::safeUrl($url);

        return $safe
            ? '<a href="'.e($safe).'" target="_blank" rel="noopener" class="text-navy underline">'.e($label ?? $short($safe)).'</a>'
            : e($label ?? $url ?? '—');
    };
    $copy = fn (string $value) => '<button type="button" class="ml-1 text-muted hover:text-navy" title="'.e(__('Copy')).'" x-on:click="navigator.clipboard.writeText('.e(json_encode($value)).')"><i class="ph ph-copy"></i></button>';
@endphp

<div class="space-y-6" x-data>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('outreach.index') }}" class="btn-secondary" wire:navigate><i class="ph ph-arrow-left"></i>{{ __('Outreach list') }}</a>
        @if($position !== null)
            <div class="flex items-center gap-2 text-xs text-muted">
                {{ $position + 1 }} {{ __('of') }} {{ count($keys) }}
                <a @class(['btn-secondary', 'pointer-events-none opacity-40' => ! $previous]) href="{{ $previous ? route('outreach.show', $previous) : '#' }}" wire:navigate>{{ __('Previous') }}</a>
                <a @class(['btn-secondary', 'pointer-events-none opacity-40' => ! $next]) href="{{ $next ? route('outreach.show', $next) : '#' }}" wire:navigate>{{ __('Next') }}</a>
            </div>
        @endif
    </div>

    <div @class(['relative overflow-hidden', 'h-60' => $p->img])>
        @if($p->img)
            <img src="{{ route('outreach.images', $p->img) }}" alt="" class="absolute inset-0 size-full object-cover brightness-75">
            <div class="absolute inset-0 bg-linear-to-t from-black/70 to-transparent"></div>
        @endif
        <div @class(['relative', 'absolute bottom-0 p-6 text-white' => $p->img])>
            <h1 @class(['page-title', '!text-white' => $p->img])>{{ $p->name }}</h1>
            <p @class(['mt-1 text-sm', 'text-white/80' => $p->img, 'text-muted' => ! $p->img])>{{ $p->region }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <span class="chip">{{ __('Tier :tier', ['tier' => $p->tier]) }}</span>
                <span class="chip">{{ __('fit :score/5', ['score' => $p->score]) }}</span>
                @foreach($p->types as $type)
                    <span class="chip border-l-2" style="border-color: {{ $typeColors[$type] ?? '#555b63' }}">{{ $type }}</span>
                @endforeach
                @foreach($p->tags as $tag)
                    @isset($tagLabels[$tag]) <span class="chip is-warn">{{ $tagLabels[$tag] }}</span> @endisset
                @endforeach
            </div>
        </div>
    </div>

    @if($p->flag || $p->client_note)
        <div class="notice">
            <p class="font-medium">{{ $p->flag ?: $p->client_note }}</p>
            @if($p->flag && $p->client_note) <p class="mt-1">{{ $p->client_note }}</p> @endif
        </div>
    @endif

    @if($email)
        @php
            $copyButton = fn (string $value, string $label) => '<button type="button" class="btn-secondary" x-data="{ done: false }" x-on:click="navigator.clipboard.writeText('.e(json_encode($value)).'); done = true; setTimeout(() => done = false, 1500)"><i class="ph ph-copy"></i><span x-text="done ? '.e(json_encode(__('Copied'))).' : '.e(json_encode($label)).'">'.e($label).'</span></button>';
            $stage = $status->stage();
            $mine = $status->claimed_by === auth()->id();
            $claimedByOther = $status->claimed_by && ! $mine;
        @endphp
        <div class="card">
            <h2 class="section-title">{{ __('Drafted email') }}</h2>

            {{-- Who is on it, and how far it got. Polls so a claim or send by someone else shows up here without a reload. --}}
            <div wire:poll.15s.visible @class([
                'mt-3 flex flex-wrap items-center justify-between gap-3 rounded border p-3 text-sm',
                'border-[#1c6f50]/30 bg-[#dcefe2]/50' => $stage === 'sent',
                'border-[#c28f1c]/40 bg-[#fdf3d8]/60' => $claimedByOther && $stage !== 'sent',
                'border-line' => ! $claimedByOther && $stage !== 'sent',
            ])>
                <p>
                    @switch($stage)
                        @case('sent')
                            <i class="ph-fill ph-check-circle text-[#1c6f50]"></i>
                            {{ __('Sent :date by :name.', ['date' => $status->sent_at?->format('M j, Y'), 'name' => $status->sender?->name ?? __('someone')]) }}
                            <a href="{{ route('pipeline') }}" class="underline" wire:navigate>{{ __('On the Pipeline as Contacted') }}</a>
                            @break
                        @case('claimed')
                            <i class="ph ph-hand"></i>
                            {{ $mine ? __('You claimed this :date.', ['date' => $status->claimed_at?->format('M j')]) : __(':name claimed this :date. Check with them before emailing.', ['name' => $status->claimer?->name ?? __('Someone'), 'date' => $status->claimed_at?->format('M j')]) }}
                            @break
                        @default
                            <span class="text-muted">{{ __('Nobody is on this one yet.') }}</span>
                    @endswitch
                    @if($stage === 'sent' && $claimedByOther)
                        <span class="text-muted">{{ __('Claimed by :name.', ['name' => $status->claimer?->name]) }}</span>
                    @endif
                </p>
                @if($canEdit)
                    <div class="flex flex-wrap items-center gap-2">
                        @if($stage === 'open')
                            <button type="button" class="btn-secondary" wire:click="claim"><i class="ph ph-hand"></i>{{ __('Claim') }}</button>
                        @elseif($stage !== 'sent' && ($mine || auth()->user()->can(\App\Enums\Permission::ManageUsers->value)) && $status->claimed_by)
                            <button type="button" class="btn-secondary" wire:click="release">{{ __('Release') }}</button>
                        @endif
                        @if($stage === 'sent')
                            <button type="button" class="btn-secondary" wire:click="undoSent" wire:confirm="{{ __('Mark this as not sent? The Pipeline entry stays; delete it there if it is wrong.') }}">{{ __('Not sent') }}</button>
                        @else
                            <button type="button" class="btn-secondary" wire:click="markSent" title="{{ __('Click once the email has gone out, from Outlook or the contact form') }}"><i class="ph ph-check"></i>{{ __('Mark as sent') }}</button>
                        @endif
                    </div>
                @endif
            </div>

            @if($canEdit)
                <details class="mt-3 text-xs">
                    <summary class="cursor-pointer text-muted">{{ $signature !== '' ? __('Your signature (added to every email here)') : __('Add your signature: emails started here don\'t get Outlook\'s automatic one') }}</summary>
                    <form wire:submit="saveSignature" class="mt-2 space-y-2">
                        <textarea wire:model="signature" rows="5" class="field-input w-full max-w-md font-sans" placeholder="{{ __("Your Name\nPartner, Formwerks Architectural\n604 555 0100 · formwerks.ca") }}"></textarea>
                        <button type="submit" class="btn-secondary">{{ __('Save signature') }}</button>
                    </form>
                </details>
            @endif

            @if($p->draft->note)
                <div class="notice mt-3"><p>{{ $p->draft->note }}</p></div>
            @endif

            @if($email['callScript'])
                <div class="mt-4 rounded border border-line p-4 text-sm">
                    <p class="font-medium"><i class="ph ph-phone"></i> {{ __('Call first: :phone', ['phone' => $p->phone]) }}</p>
                    <p class="mt-1 text-ink-2">{{ __('There is no email on file. Ask reception:') }}</p>
                    <p class="mt-2 italic">"{{ $email['callScript'] }}"</p>
                    <p class="mt-2 text-xs text-muted">{{ __('If they give one, save it below and the email goes straight to them. If not, paste the email into the website contact form.') }}</p>
                </div>
            @endif

            <dl class="mt-4 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[6rem_1fr]">
                <dt class="text-muted">{{ __('To') }}</dt>
                <dd>
                    @if($email['to'])
                        <a href="mailto:{{ $email['to'] }}" class="underline">{{ $email['to'] }}</a>{!! $copy($email['to']) !!}
                        @if($email['passAlong']) <span class="text-xs text-muted">({{ __('office inbox — no direct email for :name yet', ['name' => $person['name']]) }})</span> @endif
                    @elseif($p->contact_page)
                        {!! $link($p->contact_page, __('Website contact form')) !!} <span class="text-xs text-muted">({{ __('no email on file — paste the email into the form') }})</span>
                    @else
                        <span class="text-muted">{{ __('No email on file') }}</span>
                    @endif
                </dd>
                @if($person['name'] ?? null)
                    <dt class="text-muted pt-1.5">{{ __('Direct email') }}</dt>
                    <dd>
                        <form wire:submit="saveDirectEmail" class="flex flex-wrap items-center gap-2">
                            <input type="email" wire:model="directEmail" class="field-input max-w-72" placeholder="{{ __('From reception or LinkedIn') }}">
                            <button type="submit" class="btn-secondary">{{ __('Save') }}</button>
                        </form>
                        @error('directEmail') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-muted">{{ __('Only an address the company or person gave you. Never a guessed one.') }}</p>
                    </dd>
                @endif
            </dl>

            <div class="mt-4 space-y-3">
                <div class="text-sm"><span class="text-muted">{{ __('Subject') }}</span><p class="mt-1">{{ $email['subject'] }}</p></div>
                <div class="whitespace-pre-line rounded border border-line bg-white p-4 text-sm leading-relaxed">{{ $email['body'] }}</div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ $email['outlook'] }}" target="_blank" rel="noopener" class="btn"><i class="ph ph-microsoft-outlook-logo"></i>{{ __('Open in Outlook') }}</a>
                    {!! $copyButton($email['body'], __('Copy email')) !!}
                    {!! $copyButton($email['subject'], __('Copy subject')) !!}
                </div>
                <p class="text-xs text-muted">
                    {{ __('Open in Outlook opens it in the browser, written and signed. Make any changes there, attach the portfolio, send, then click Mark as sent.') }}
                    @unless($email['to']) {{ __('No address yet, so the To line will be blank: add it once you have one.') }} @endunless
                    <a href="{{ $email['mailto'] }}" class="underline">{{ __('Use this computer\'s mail app instead') }}</a>
                </p>
            </div>

            @if($email['linkedinNote'])
                <div class="mt-6 border-t border-line pt-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-medium">{{ __('LinkedIn note, same day') }}</h3>
                        {!! $copyButton($email['linkedinNote'], __('Copy note')) !!}
                    </div>
                    <p class="mt-1 text-xs text-muted">{!! $link($person['linkedin'], __(':name on LinkedIn', ['name' => $person['name']])) !!} · {{ __(':count of 300 characters', ['count' => mb_strlen($email['linkedinNote'])]) }}</p>
                    <div class="mt-2 rounded border border-line bg-white p-4 text-sm">{{ $email['linkedinNote'] }}</div>
                </div>
            @endif
        </div>
    @endif

    <div class="card">
        <div class="flex items-center gap-3">
            <h2 class="section-title">{{ __('Activity') }}</h2>
            <x-outreach.activity-pill :prospect="$p" />
        </div>
        <p class="section-note">{{ ($a['checked'] ?? null) ? __('Checked :date. Based on public listings, not a guarantee.', ['date' => $a['checked']]) : __('Not researched yet.') }}</p>
        <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
            <dt class="text-muted">{{ __('Latest project') }}</dt><dd>{{ $a['project'] ?? '—' }}@if($a['stage'] ?? null) ({{ $a['stage'] }})@endif</dd>
            <dt class="text-muted">{{ __('Last milestone') }}</dt><dd>{{ $a['milestone'] ?? '—' }}</dd>
            <dt class="text-muted">{{ __('Website') }}</dt><dd>{{ ['live' => __('live'), 'down' => __('down')][$a['site'] ?? ''] ?? __('not checked') }}</dd>
            <dt class="text-muted">{{ __('Notes') }}</dt><dd>{{ $a['notes'] ?? '—' }}</dd>
            <dt class="text-muted">{{ __('Evidence') }}</dt><dd>{!! $link($a['evidence'] ?? null) !!}</dd>
        </dl>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card">
            <h2 class="section-title">{{ __('Who to contact') }}</h2>
            <div class="mt-4 space-y-2 text-sm">
                @if($person)
                    <p class="font-medium">
                        {{ $person['name'] }}
                        @if(($person['confidence'] ?? '') === 'low') <span class="chip is-warn">{{ __('unverified — confirm first') }}</span> @endif
                    </p>
                    @if($person['title'] ?? null) <p class="text-muted">{{ $person['title'] }}</p> @endif
                    @if($person['email'] ?? null) <p><a href="mailto:{{ $person['email'] }}" class="underline">{{ $person['email'] }}</a>{!! $copy($person['email']) !!}</p> @endif
                    @if($person['linkedin'] ?? null) <p>{!! $link($person['linkedin'], 'LinkedIn') !!}</p> @endif
                    @if($person['source'] ?? null) <p class="text-xs">{!! $link($person['source'], __('where this came from')) !!}</p> @endif
                    @if($person['pattern'] ?? null) <p class="text-xs text-muted">{{ __('Email pattern, not confirmed:') }} {{ preg_replace('/\s*\(evidence:.*\)$/', '', $person['pattern']) }}</p> @endif
                @else
                    <p class="text-muted">{{ __('No named person found yet. Use the office contact below.') }}</p>
                @endif

                <dl class="mt-4 grid gap-x-4 gap-y-2 border-t border-line pt-4 sm:grid-cols-[8rem_1fr]">
                    @if($p->others) <dt class="text-muted">{{ __('Other people') }}</dt><dd>{{ $p->others }}</dd> @endif
                    @if($p->email) <dt class="text-muted">{{ __('Office email') }}</dt><dd><a href="mailto:{{ $p->email }}" class="underline">{{ $p->email }}</a>{!! $copy($p->email) !!}</dd> @endif
                    @if($p->phone) <dt class="text-muted">{{ __('Phone') }}</dt><dd><a href="tel:{{ preg_replace('/[^\d+]/', '', $p->phone) }}" class="underline">{{ $p->phone }}</a></dd> @endif
                    @if($p->contact_page) <dt class="text-muted">{{ __('Contact page') }}</dt><dd>{!! $link($p->contact_page) !!}</dd> @endif
                    @if($p->website)
                        <dt class="text-muted">{{ __('Website') }}</dt>
                        <dd>{!! $link($p->website) !!} @unless(in_array($p->webcheck, ['ok', 'fixed'], true)) <span class="chip is-warn">{{ $p->webcheck ?: __('not checked') }}</span> @endunless</dd>
                    @endif
                </dl>

                @if($p->contact_notes || $p->route)
                    <details class="mt-3 text-xs text-ink-2">
                        <summary class="cursor-pointer">{{ __('Contact research notes') }}</summary>
                        <p class="mt-2 whitespace-pre-line">{{ $p->route }}</p>
                        <p class="mt-2 whitespace-pre-line">{{ $p->contact_notes }}</p>
                    </details>
                @endif
            </div>
        </div>

        <div class="card">
            <h2 class="section-title">{{ __('The company') }}</h2>
            @if($p->why) <p class="mt-3 text-sm">{{ $p->why }}</p> @endif
            <dl class="mt-4 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[9rem_1fr]">
                <dt class="text-muted">{{ __('Head office') }}</dt><dd>{{ $p->hq ?? '—' }}</dd>
                <dt class="text-muted">{{ __('Builds') }}</dt><dd>{{ implode(', ', $p->types) }}@if($p->product) — {{ $p->product }}@endif</dd>
                <dt class="text-muted">{{ __('Typical size') }}</dt><dd>{{ $p->size ?? '—' }}</dd>
                <dt class="text-muted">{{ __('Style') }}</dt><dd>{{ $p->style ?? '—' }}</dd>
                <dt class="text-muted">{{ __('Architect they use') }}</dt><dd>{{ $p->architect ?? '—' }}</dd>
                <dt class="text-muted">{{ __('Phased land') }}</dt><dd>{{ $p->phased ?? '—' }}</dd>
                <dt class="text-muted">{{ __('Research confidence') }}</dt><dd>{{ $p->confidence ?? '—' }}</dd>
            </dl>
        </div>
    </div>

    <div class="card">
        @if($p->projects->isNotEmpty())
            <h2 class="section-title">{{ __('Projects (:count)', ['count' => $p->projects->count()]) }}</h2>
            <p class="section-note">{{ __('From the regional deck and the project map. Images are each project page\'s own share image.') }}</p>
            <div class="mt-4 grid gap-px border border-[#e7e9ea] bg-[#e7e9ea] sm:grid-cols-2 xl:grid-cols-3">
                @foreach($p->projects as $project)
                    @php
                        $units = $project->units && preg_match('/^[\d\s–-]+$/u', $project->units) ? "{$project->units} ".__('units') : $project->units;
                        $map = $project->lat !== null
                            ? 'https://www.google.com/maps/search/?api=1&query='.($project->exact ? "{$project->lat},{$project->lon}" : urlencode("{$project->name} {$project->where_text}"))
                            : null;
                    @endphp
                    <div class="bg-white">
                        @if($project->img)
                            <img src="{{ route('outreach.images', $project->img) }}" alt="" loading="lazy" class="h-32 w-full object-cover">
                        @else
                            <div class="flex h-32 items-center justify-center bg-rule text-2xl text-muted"><i class="ph ph-buildings"></i></div>
                        @endif
                        <div class="space-y-1 p-3 text-sm">
                            <p class="font-medium">{!! $link($project->url, $project->name ?? '—') !!} @if($project->hero) <span class="chip is-on">{{ __('flagship') }}</span> @endif</p>
                            <p class="text-xs text-muted">{{ collect([$project->where_text, $units])->filter()->implode(' · ') }}</p>
                            @if($project->away) <p class="text-xs text-muted">{{ $project->away }}</p> @endif
                            @if($map) <p class="text-xs"><a href="{{ $map }}" target="_blank" rel="noopener" class="underline">{{ __('Map') }}</a></p> @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <h2 class="section-title">{{ __('Recent projects') }}</h2>
            <p class="section-note">{{ __('As listed in the research. This developer isn\'t in a regional deck, so projects aren\'t individually linked or mapped.') }}</p>
            @if($p->recent)
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                    @foreach($p->recent as $recent) <li>{{ $recent }}</li> @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-muted">{{ __('None recorded.') }}</p>
            @endif
        @endif
    </div>

    @if($p->sources)
        <div class="card">
            <h2 class="section-title">{{ __('Where the research found them') }} @if($p->passes) <span class="text-sm text-muted">({{ $p->passes }})</span> @endif</h2>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach($p->sources as $source) <li>{!! $link($source) !!}</li> @endforeach
            </ul>
        </div>
    @endif
</div>
