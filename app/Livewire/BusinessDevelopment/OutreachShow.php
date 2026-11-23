<?php

namespace App\Livewire\BusinessDevelopment;

use App\Models\OutreachProspect;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class OutreachShow extends Component
{
    public OutreachProspect $prospect;

    /** @var array<int, string> */
    public array $keys = [];

    public string $directEmail = '';

    public function mount(string $prospectKey): void
    {
        $this->prospect = OutreachProspect::with(['projects', 'draft'])->where('key', $prospectKey)->firstOrFail();

        $this->keys = session('outreach.order', []);
        $this->directEmail = (string) $this->prospect->draft?->direct_email;
    }

    public static function safeUrl(?string $url): ?string
    {
        return $url && preg_match('#^https?://#i', $url) ? $url : null;
    }

    public function saveDirectEmail(): void
    {
        $this->directEmail = trim($this->directEmail);

        $this->validate(['directEmail' => ['nullable', 'email:rfc', 'max:255']]);

        $this->prospect->draft?->update(['direct_email' => $this->directEmail ?: null]);
    }

    /**
     * The drafted email as it should go out. Without a direct address for the named contact,
     * it goes to the office inbox with their name in the subject and a line asking for it to be passed on.
     *
     * @return ?array{to: ?string, subject: string, body: string, passAlong: bool, direct: bool, linkedinNote: ?string, callScript: ?string}
     */
    public function email(): ?array
    {
        $draft = $this->prospect->draft;

        if (! $draft) {
            return null;
        }

        $name = trim((string) ($this->prospect->person['name'] ?? '')) ?: null;
        $firstName = $name ? Str::before($name, ' ') : null;
        $direct = $draft->direct_email ?: (($this->prospect->person['email'] ?? null) ?: null);
        $office = $this->prospect->email;
        $passAlong = $name && ! $direct;

        $linkedinNote = $passAlong && ($this->prospect->person['linkedin'] ?? null)
            ? ($office
                ? "Hi {$firstName}, I just sent a note to your ".Str::before($office, '@')."@ inbox about a free site study for your next project. I'd like to connect here as well. Formwerks designs traditional wood-frame townhome and low-rise communities."
                : "Hi {$firstName}, I'd like to connect. Formwerks designs traditional wood-frame townhome and low-rise communities, and we offer a free site study for your next project.")
            : null;

        return [
            'to' => $direct ?: $office,
            'subject' => $passAlong ? "For {$name}: {$draft->subject}" : $draft->subject,
            'body' => $passAlong ? "Could you please pass this along to {$firstName}? Thank you.\n\n{$draft->body}" : $draft->body,
            'passAlong' => (bool) $passAlong,
            'direct' => (bool) $direct,
            'linkedinNote' => $linkedinNote,
            'callScript' => $passAlong && ! $office && $this->prospect->phone
                ? "Hi, I'd like to send a short note to {$name}. What's the best email address to reach {$firstName}?"
                : null,
        ];
    }

    public function render(): View
    {
        $position = array_search($this->prospect->key, $this->keys, true);

        return view('livewire.business-development.outreach-show', [
            'position' => $position === false ? null : $position,
            'previous' => $position === false ? null : ($this->keys[$position - 1] ?? null),
            'next' => $position === false ? null : ($this->keys[$position + 1] ?? null),
            'typeColors' => OutreachIndex::typeColors(),
            'email' => $this->email(),
        ])->title($this->prospect->name);
    }
}
