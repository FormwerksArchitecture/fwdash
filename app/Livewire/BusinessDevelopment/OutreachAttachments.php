<?php

namespace App\Livewire\BusinessDevelopment;

use App\Enums\Permission;
use App\Models\OutreachAttachment;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The PDFs partners can attach to an outreach email. The upload itself goes
 * through OutreachAttachmentsController in pieces; this lists and manages them.
 *
 * @property-read Collection<int, OutreachAttachment> $attachments
 */
class OutreachAttachments extends Component
{
    /** @return Collection<int, OutreachAttachment> */
    #[Computed]
    public function attachments(): Collection
    {
        return OutreachAttachment::ready()->with('uploader')->get();
    }

    #[On('outreach-attachment-uploaded')]
    public function refreshList(): void
    {
        unset($this->attachments);
    }

    public function toggleDefault(int $id): void
    {
        $this->authorize(Permission::EditBusinessDevelopment->value);

        $attachment = OutreachAttachment::findOrFail($id);
        $attachment->update(['is_default' => ! $attachment->is_default]);

        unset($this->attachments);
    }

    public function remove(int $id): void
    {
        $this->authorize(Permission::EditBusinessDevelopment->value);

        OutreachAttachment::findOrFail($id)->delete();

        unset($this->attachments);
    }

    public function render(): View
    {
        // Uploads abandoned part-way (a closed tab) are cleared after a day.
        OutreachAttachment::where('complete', false)->where('created_at', '<', now()->subDay())->delete();

        return view('livewire.business-development.outreach-attachments', [
            'maxMb' => OutreachAttachment::MaxBytes / 1024 / 1024,
        ]);
    }
}
