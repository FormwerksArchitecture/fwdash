@php
    use App\Enums\Permission;
    use App\Support\Format;

    $row = $detail['row'];
    $grid = $detail['grid'];
    $lines = $detail['lines'];
    $unconfirmed = $lines->whereNull('confirmedAt');
    $waiting = $unconfirmed->sum(fn (array $line) => (float) $line['total']);
    $sources = ['imported' => __('Workbook'), 'timesheet' => __('Timesheet')];
    $canConfirm = auth()->user()->can(Permission::EditBilling->value);
    $facts = [
        __('Developer') => $row['developer'] ?? '—',
        __('Division') => $row['division'] ?? '—',
        __('Lead') => $grid['lead'] ?? '—',
        __('Contract') => Format::money($row['contract']),
        __('Billed to date') => Format::money($row['billed']),
        __('Left to bill') => Format::money($row['balance']),
        __('Paid to date') => Format::money($row['paymentsTotal']),
        __('Last payment') => $row['lastPaymentDate'] ? Format::money($row['lastPaymentAmount']).' · '.Format::date($row['lastPaymentDate']) : __('none recorded'),
        __('Outstanding') => Format::money($row['outstanding']),
    ];
@endphp

<tr wire:key="me-detail-{{ $row['jobNumber'] }}" class="hover:bg-transparent!">
    <td colspan="9" class="border-l-2 border-l-navy bg-[#f7f8f9] px-6! py-5!" x-on:click.stop>
        {{-- Held to the left of the scrolling table, so the panel stays in view however wide the table runs. --}}
        <div class="sticky left-6 w-[calc(100cqw-3.25rem)] max-w-[58rem]">
        <div class="grid gap-x-10 gap-y-6 md:grid-cols-[minmax(0,17rem)_minmax(0,19rem)_1fr]">
            <div>
                <h3 class="field-label">{{ __('Billed in :month', ['month' => Format::month($period)]) }}</h3>
                <dl class="space-y-1 text-sm">
                    @forelse($detail['draws'] as $draw)
                        <div class="flex justify-between gap-4">
                            <dt class="text-ink-2">{{ $draw['phase'] }} @if($draw['pctThisMonth'] !== null)<span class="text-xs text-muted">{{ __('to :pct', ['pct' => Format::pct($draw['pctThisMonth'])]) }}</span>@endif</dt>
                            <dd class="tabular-nums">{{ Format::money($draw['billedThisMonth']) }}</dd>
                        </div>
                    @empty
                        <p class="text-muted">{{ __('No phase draws this month.') }}</p>
                    @endforelse
                    <div class="flex justify-between gap-4">
                        <dt class="text-ink-2">{{ __('Hourly') }}</dt>
                        <dd class="tabular-nums">{{ Format::money($grid['totals']['hourlyBilled']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-line pt-1 font-medium">
                        <dt>{{ __('Total') }}</dt>
                        <dd class="tabular-nums">{{ Format::money($grid['totals']['billed']) }}</dd>
                    </div>
                </dl>
            </div>

            <div>
                <h3 class="field-label">{{ __('Where the job stands') }}</h3>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    @foreach($facts as $label => $value)
                        <dt class="text-ink-2">{{ $label }}</dt>
                        <dd class="text-right tabular-nums">{{ $value }}</dd>
                    @endforeach
                </dl>
            </div>

            <div class="md:text-right">
                <a href="{{ route('projects.show', $row['jobNumber']) }}" wire:navigate class="btn-secondary whitespace-nowrap">{{ __('Open project') }} <i class="ph ph-arrow-right"></i></a>
            </div>
        </div>

        <div class="mt-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="field-label mb-0">
                    {{ __('Extra hours this month') }}
                    @if($lines->isNotEmpty())
                        <span class="ml-2 font-normal tracking-normal normal-case text-muted">{{ __(':done of :count confirmed', ['done' => $lines->count() - $unconfirmed->count(), 'count' => $lines->count()]) }}</span>
                    @endif
                    @if($unconfirmed->isNotEmpty())
                        <span class="ml-2 font-normal tracking-normal normal-case text-[#8a6a15]">{{ __(':amount waiting — not billed until confirmed', ['amount' => Format::money($waiting, 2)]) }}</span>
                    @endif
                </h3>
                @if($canConfirm && $unconfirmed->isNotEmpty())
                    <button type="button" class="btn-secondary" wire:click="confirmLines({{ json_encode($unconfirmed->pluck('id')->values()) }}, true)">
                        <i class="ph ph-check"></i> {{ __('Confirm all :count', ['count' => $unconfirmed->count()]) }}
                    </button>
                @endif
            </div>

            @if($lines->isEmpty())
                <p class="mt-2 text-sm text-muted">{{ __('No hourly lines for this job this month. Time staff log against an active extra on their timesheets lands here to confirm.') }}</p>
            @else
                <div class="mt-2 overflow-x-auto">
                <table class="data-table bg-white">
                    <thead>
                        <tr>
                            <th class="w-10"><span class="sr-only">{{ __('Confirmed') }}</span></th>
                            <th>{{ __('Staff') }}</th>
                            <th class="is-num">{{ __('Hours') }}</th>
                            <th class="is-num">{{ __('Rate') }}</th>
                            <th class="is-num">{{ __('Total') }}</th>
                            <th>{{ __('Extra') }}</th>
                            <th>{{ __('Note') }}</th>
                            <th>{{ __('Source') }}</th>
                            <th>{{ __('Confirmed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lines as $line)
                            @php $confirmed = $line['confirmedAt'] !== null; @endphp
                            <tr wire:key="me-line-{{ $line['id'] }}" @class(['text-muted' => $confirmed])>
                                <td>
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-[#2f8a67]"
                                        @checked($confirmed)
                                        @disabled(! $canConfirm)
                                        wire:click="confirmLines([{{ $line['id'] }}], {{ $confirmed ? 'false' : 'true' }})"
                                        aria-label="{{ __('Confirm :staff’s :hours h', ['staff' => $line['staff'] ?? '', 'hours' => Format::number($line['hours'], 2)]) }}"
                                    >
                                </td>
                                <td>{{ $line['staff'] ?? '—' }}</td>
                                <td class="is-num">{{ $line['hours'] !== null ? Format::number($line['hours'], 2) : '—' }}</td>
                                <td class="is-num">{!! $line['rate'] !== null ? e(Format::money($line['rate'])) : '<span class="text-over" title="'.e(__('No rate for this person on this job: set one on the entry screen')).'">'.e(__('no rate')).'</span>' !!}</td>
                                <td class="is-num">
                                    {{ Format::money($line['total'], 2) }}
                                    @if($line['amount'] !== null && (float) $line['amount'] == 0)
                                        <span class="chip ml-1">{{ __('not billed') }}</span>
                                    @endif
                                </td>
                                <td>{{ $line['extraId'] && isset($detail['extras'][$line['extraId']]) ? $detail['extras'][$line['extraId']]->label() : '—' }}</td>
                                <td>{{ $line['note'] ?? '—' }}</td>
                                <td><span @class(['chip', 'is-info' => $line['rateSource'] === 'timesheet'])>{{ $sources[$line['rateSource']] ?? __('Typed') }}</span></td>
                                <td class="whitespace-nowrap text-xs">
                                    @if($confirmed)
                                        <span class="text-[#2f6b52]"><i class="ph ph-check-circle"></i> {{ $line['confirmedBy'] }} · {{ Format::date(substr((string) $line['confirmedAt'], 0, 10)) }}</span>
                                    @else
                                        <span class="text-[#8a6a15]">{{ __('Not billed yet') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
        </div>
    </td>
</tr>
