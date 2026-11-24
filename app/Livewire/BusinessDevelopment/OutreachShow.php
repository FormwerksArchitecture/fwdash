<?php

namespace App\Livewire\BusinessDevelopment;

use App\Enums\Permission;
use App\Enums\PursuitStage;
use App\Models\OutreachProspect;
use App\Models\OutreachStatus;
use App\Models\Pursuit;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

class OutreachShow extends Component
{
    public OutreachProspect $prospect;

    /** @var array<int, string> */
    public array $keys = [];

    public string $directEmail = '';

    public string $signature = '';

    public function mount(string $prospectKey): void
    {
        $this->prospect = OutreachProspect::with(['projects', 'draft'])->where('key', $prospectKey)->firstOrFail();

        $this->keys = session('outreach.order', []);
        $this->directEmail = (string) $this->prospect->draft?->direct_email;
        $this->signature = (string) auth()->user()->email_signature;
    }

    public function status(): OutreachStatus
    {
        return OutreachStatus::with(['claimer', 'sender'])->firstOrNew(['prospect_key' => $this->prospect->key]);
    }

    public function claim(): void
    {
        $this->authorize(Permission::EditBusinessDevelopment->value);

        $status = $this->status();

        if ($status->claimed_by && $status->claimed_by !== auth()->id()) {
            Flux::toast(__(':name claimed this first.', ['name' => $status->claimer->name]), variant: 'warning');

            return;
        }

        $status->fill(['claimed_by' => auth()->id(), 'claimed_at' => now()])->save();
    }

    /** Only the partner who claimed it, or an admin, can let it go. */
    public function release(): void
    {
        $status = $this->status();

        abort_unless($status->claimed_by === auth()->id() || auth()->user()->can(Permission::ManageUsers->value), 403);

        $status->fill(['claimed_by' => null, 'claimed_at' => null])->save();
    }

    public function saveSignature(): void
    {
        $this->authorize(Permission::EditBusinessDevelopment->value);

        $this->validate(['signature' => ['nullable', 'string', 'max:2000']]);

        auth()->user()->forceFill(['email_signature' => trim($this->signature) ?: null])->save();

        Flux::toast(__('Signature saved.'));
    }

    /** The partner says the email went out: from Outlook, the contact form, or anywhere else. */
    public function markSent(): void
    {
        $this->authorize(Permission::EditBusinessDevelopment->value);

        $this->recordSent(auth()->user());
    }

    public function undoSent(): void
    {
        $this->authorize(Permission::EditBusinessDevelopment->value);

        $this->status()->fill(['sent_by' => null, 'sent_at' => null])->save();
    }

    /** A sent email starts a pursuit on the Pipeline, once. */
    protected function recordSent(User $sender): void
    {
        $sentAt = now();
        $status = $this->status();

        $status->fill([
            'claimed_by' => $status->claimed_by ?? $sender->id,
            'claimed_at' => $status->claimed_at ?? $sentAt,
            'sent_by' => $sender->id,
            'sent_at' => $sentAt,
        ]);

        if (! $status->pursuit_id || ! Pursuit::whereKey($status->pursuit_id)->exists()) {
            $firstName = Str::before($sender->name, ' ');

            $status->pursuit_id = Pursuit::create([
                'developer' => $this->prospect->name,
                'stage' => PursuitStage::Contacted,
                'owner' => in_array($firstName, config('bizdev.owners'), true) ? $firstName : null,
                'note' => __('First outreach email sent :date by :name.', ['date' => $sentAt->format('M j, Y'), 'name' => $sender->name]),
            ])->id;
        }

        $status->save();
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
     * The partner's saved signature closes it, since Outlook doesn't add its own to an email started from a link.
     *
     * @return ?array{to: ?string, subject: string, body: string, passAlong: bool, direct: bool, linkedinNote: ?string, callScript: ?string, mailto: string, outlook: string}
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

        $to = $direct ?: $office;
        $subject = $passAlong ? "For {$name}: {$draft->subject}" : $draft->subject;
        $body = $passAlong ? "Could you please pass this along to {$firstName}? Thank you.\n\n{$draft->body}" : $draft->body;
        $signature = trim((string) auth()->user()?->email_signature);
        $body = $signature === '' ? $body : "{$body}\n\n{$signature}";

        return [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'passAlong' => (bool) $passAlong,
            'direct' => (bool) $direct,
            'linkedinNote' => $linkedinNote,
            'callScript' => $passAlong && ! $office && $this->prospect->phone
                ? "Hi, I'd like to send a short note to {$name}. What's the best email address to reach {$firstName}?"
                : null,
            'mailto' => self::mailtoUrl($to, $subject, $body),
            'outlook' => self::outlookComposeUrl($to, $subject, $body),
        ];
    }

    /** Opens a new message in the computer's mail app (the Outlook app when it is the default), already written, and addressed when there is an address. */
    public static function mailtoUrl(?string $to, string $subject, string $body): string
    {
        return 'mailto:'.$to.'?subject='.rawurlencode($subject).'&body='.rawurlencode(str_replace("\n", "\r\n", $body));
    }

    /** Opens a new message in Outlook on the web, already written, and addressed when there is an address. Closing it unsent keeps it in Drafts. */
    public static function outlookComposeUrl(?string $to, string $subject, string $body): string
    {
        return 'https://outlook.office.com/mail/deeplink/compose?'
            .http_build_query(['to' => (string) $to, 'subject' => $subject, 'body' => $body], '', '&', PHP_QUERY_RFC3986);
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
            'status' => $this->status(),
            'canEdit' => auth()->user()->can(Permission::EditBusinessDevelopment->value),
        ])->title($this->prospect->name);
    }
}
