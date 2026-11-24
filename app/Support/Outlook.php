<?php

namespace App\Support;

use App\Models\OutreachAttachment;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/*
 * Puts a drafted email, PDFs attached, in a partner's own Outlook Drafts folder
 * through Microsoft Graph, using the delegated Mail.ReadWrite permission they
 * granted with "Connect Outlook". Nothing is ever sent from here: the partner
 * reviews the draft in Outlook and presses Send themselves.
 */
class Outlook
{
    public const Scopes = ['offline_access', 'User.Read', 'Mail.ReadWrite'];

    protected const GraphUrl = 'https://graph.microsoft.com/v1.0';

    /** Graph takes an attachment inline up to 3 MB; anything bigger goes through an upload session. */
    protected const InlineLimit = 3 * 1024 * 1024;

    /** Upload-session slices must be a multiple of 320 KiB. */
    protected const SliceBytes = 10 * 320 * 1024;

    /**
     * @param  array<int, OutreachAttachment>  $attachments
     * @return array{id: string, webLink: string}
     */
    public function createDraft(User $user, ?string $to, string $subject, string $body, array $attachments): array
    {
        $message = $this->graph($user)
            ->post('/me/messages', [
                'subject' => $subject,
                'body' => ['contentType' => 'Text', 'content' => $body],
                'toRecipients' => $to ? [['emailAddress' => ['address' => $to]]] : [],
            ])
            ->throw()
            ->json();

        foreach ($attachments as $attachment) {
            $this->attach($user, $message['id'], $attachment);
        }

        return ['id' => $message['id'], 'webLink' => $message['webLink']];
    }

    /** Stores the tokens from a Microsoft sign-in that granted Mail.ReadWrite. */
    public function connect(User $user, string $accessToken, ?string $refreshToken, ?int $expiresIn): void
    {
        $user->forceFill([
            'outlook_access_token' => $accessToken,
            'outlook_refresh_token' => $refreshToken ?: $user->outlook_refresh_token,
            'outlook_expires_at' => now()->addSeconds($expiresIn ?: 3600),
        ])->save();
    }

    public function disconnect(User $user): void
    {
        $user->forceFill(['outlook_access_token' => null, 'outlook_refresh_token' => null, 'outlook_expires_at' => null])->save();
    }

    protected function attach(User $user, string $messageId, OutreachAttachment $attachment): void
    {
        $contents = $attachment->contents();
        $path = '/me/messages/'.rawurlencode($messageId).'/attachments';

        if (strlen($contents) < self::InlineLimit) {
            $this->graph($user)
                ->post($path, [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => $attachment->filename,
                    'contentType' => 'application/pdf',
                    'contentBytes' => base64_encode($contents),
                ])
                ->throw();

            return;
        }

        $uploadUrl = $this->graph($user)
            ->post("{$path}/createUploadSession", [
                'AttachmentItem' => ['attachmentType' => 'file', 'name' => $attachment->filename, 'size' => strlen($contents)],
            ])
            ->throw()
            ->json('uploadUrl');

        $total = strlen($contents);

        for ($start = 0; $start < $total; $start += self::SliceBytes) {
            $slice = substr($contents, $start, self::SliceBytes);
            $end = $start + strlen($slice) - 1;

            // The upload URL carries its own authorisation; Graph rejects a bearer token on it.
            Http::timeout(30)
                ->withHeaders(['Content-Range' => "bytes {$start}-{$end}/{$total}"])
                ->withBody($slice, 'application/octet-stream')
                ->put($uploadUrl)
                ->throw();
        }
    }

    protected function graph(User $user): PendingRequest
    {
        return Http::baseUrl(self::GraphUrl)
            ->timeout(20)
            ->withToken($this->accessToken($user))
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"']);
    }

    protected function accessToken(User $user): string
    {
        if (! $user->hasOutlook()) {
            throw new OutlookNotConnectedException;
        }

        if ($user->outlook_access_token && $user->outlook_expires_at?->isAfter(now()->addMinutes(2))) {
            return $user->outlook_access_token;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->post('https://login.microsoftonline.com/'.config('services.azure.tenant').'/oauth2/v2.0/token', [
                'grant_type' => 'refresh_token',
                'client_id' => config('services.azure.client_id'),
                'client_secret' => config('services.azure.client_secret'),
                'refresh_token' => $user->outlook_refresh_token,
                'scope' => implode(' ', self::Scopes),
            ]);

        if ($response->clientError()) {
            // Revoked, expired after 90 days unused, or a password change: the partner has to connect again.
            $this->disconnect($user);

            throw new OutlookNotConnectedException;
        }

        $response->throw();

        $this->connect($user, $response->json('access_token'), $response->json('refresh_token'), $response->json('expires_in'));

        return $user->outlook_access_token;
    }

    /** A short reason to show the partner when Graph says no. */
    public static function describe(RequestException $exception): string
    {
        $message = $exception->response->json('error.message') ?? $exception->getMessage();

        return $exception->response->status() === 403
            ? __('Outlook refused (:message). Your Microsoft 365 admin may need to approve the Mail.ReadWrite permission for the dashboard.', ['message' => $message])
            : __('Outlook said: :message', ['message' => $message]);
    }
}
