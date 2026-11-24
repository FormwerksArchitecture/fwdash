@php
    use App\Enums\Permission;

    $canEdit = auth()->user()->can(Permission::EditBusinessDevelopment->value);
@endphp

<details class="card" @if($this->attachments->isEmpty() && $canEdit) open @endif>
    <summary class="flex cursor-pointer items-center justify-between gap-3">
        <span class="section-title">{{ __('PDFs to attach') }} <span class="text-sm font-normal text-muted">({{ $this->attachments->count() }})</span></span>
        <span class="text-xs text-muted">{{ __('Ticked ones are pre-selected on every drafted email') }}</span>
    </summary>

    <div class="mt-4 space-y-4 text-sm">
        @if($this->attachments->isEmpty())
            <p class="text-muted">{{ __('No PDFs yet. Upload the portfolio so partners can pick it when they draft an email.') }}</p>
        @else
            <ul class="divide-y divide-line border border-line">
                @foreach($this->attachments as $attachment)
                    <li class="flex flex-wrap items-center gap-3 px-3 py-2" wire:key="att-{{ $attachment->id }}">
                        <i class="ph ph-file-pdf text-lg text-[#a83f24]"></i>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $attachment->name }}</p>
                            <p class="text-xs text-muted">
                                {{ $attachment->filename }} · {{ $attachment->sizeLabel() }}
                                @if($attachment->uploader) · {{ __('added by :name', ['name' => $attachment->uploader->name]) }} @endif
                                @if($attachment->size < 4 * 1024 * 1024) · <a href="{{ route('outreach.attachments.show', $attachment) }}" target="_blank" class="underline">{{ __('view') }}</a> @endif
                            </p>
                        </div>
                        @if($canEdit)
                            <label class="flex items-center gap-1.5 text-xs">
                                <input type="checkbox" @checked($attachment->is_default) wire:click="toggleDefault({{ $attachment->id }})">
                                {{ __('Pre-select') }}
                            </label>
                            <button type="button" class="text-xs text-danger underline" wire:click="remove({{ $attachment->id }})" wire:confirm="{{ __('Remove :name from the PDF list? Drafts already in Outlook keep their copy.', ['name' => $attachment->name]) }}">{{ __('Remove') }}</button>
                        @elseif($attachment->is_default)
                            <span class="chip is-on">{{ __('pre-selected') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if($canEdit)
            <form class="flex flex-wrap items-end gap-3" x-data="outreachPdfUpload" x-on:submit.prevent="upload">
                <label class="grid gap-1">
                    <span class="text-xs text-muted">{{ __('Name partners will see') }}</span>
                    <input type="text" x-model="name" class="field-input w-64" placeholder="{{ __('Formwerks portfolio 2026') }}" required maxlength="120">
                </label>
                <label class="grid gap-1">
                    <span class="text-xs text-muted">{{ __('PDF, up to :mb MB', ['mb' => $maxMb]) }}</span>
                    <input type="file" accept="application/pdf,.pdf" x-ref="file" x-on:change="pick" class="text-xs" required>
                </label>
                <button type="submit" class="btn" x-bind:disabled="busy">
                    <i class="ph ph-upload-simple"></i>
                    <span x-text="busy ? @js(__('Uploading')) + ' ' + progress + '%' : @js(__('Upload PDF'))">{{ __('Upload PDF') }}</span>
                </button>
                <p class="w-full text-xs text-danger" x-show="error" x-text="error"></p>
            </form>
        @endif
    </div>
</details>

@script
<script>
    Alpine.data('outreachPdfUpload', () => ({
        name: '',
        busy: false,
        progress: 0,
        error: '',

        pick() {
            const file = this.$refs.file.files[0];

            if (file && ! this.name) {
                this.name = file.name.replace(/\.pdf$/i, '').replace(/[_-]+/g, ' ');
            }
        },

        async send(url, method, body, contentType = 'application/json') {
            const response = await fetch(url, {
                method,
                body,
                headers: {
                    'Content-Type': contentType,
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? @js(csrf_token()),
                },
            });

            const json = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(json.message || @js(__('The upload failed. Please try again.')));
            }

            return json;
        },

        async upload() {
            const file = this.$refs.file.files[0];

            if (! file) {
                return;
            }

            this.busy = true;
            this.error = '';
            this.progress = 0;

            try {
                const base = @js(route('outreach.attachments.store'));
                const created = await this.send(base, 'POST', JSON.stringify({ name: this.name, filename: file.name, size: file.size }));

                for (let position = 0; position < created.chunks; position++) {
                    const slice = file.slice(position * created.chunkBytes, (position + 1) * created.chunkBytes);
                    await this.send(`${base}/${created.id}/chunks/${position}`, 'PUT', slice, 'application/octet-stream');
                    this.progress = Math.round(((position + 1) / created.chunks) * 100);
                }

                await this.send(`${base}/${created.id}/complete`, 'POST', '{}');

                this.name = '';
                this.$refs.file.value = '';
                $wire.dispatch('outreach-attachment-uploaded');
            } catch (exception) {
                this.error = exception.message;
            } finally {
                this.busy = false;
            }
        },
    }));
</script>
@endscript
