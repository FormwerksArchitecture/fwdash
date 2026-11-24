<?php

namespace App\Http\Controllers\Auth;

use App\Actions\SignInWithMicrosoftAction;
use App\Http\Controllers\Controller;
use App\Support\Outlook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as OAuthUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class MicrosoftController extends Controller
{
    public function redirect(): SymfonyRedirectResponse
    {
        if (blank(config('services.azure.client_id'))) {
            return to_route('login')->withErrors(['microsoft' => __('Microsoft sign-in is not set up yet.')]);
        }

        return Socialite::driver('azure')->redirect();
    }

    /**
     * Asks Microsoft for permission to write to the signed-in partner's mailbox, so the
     * dashboard can put outreach drafts in their Outlook. Only partners who use outreach
     * are asked; everyone else signs in with User.Read alone.
     */
    public function connectOutlook(): SymfonyRedirectResponse
    {
        session(['microsoft.connect_outlook' => url()->previous(route('outreach.index'))]);

        /** @var AbstractProvider $provider */
        $provider = Socialite::driver('azure');

        return $provider
            ->scopes(Outlook::Scopes)
            ->with(['login_hint' => auth()->user()->email, 'prompt' => 'consent'])
            ->redirect();
    }

    public function disconnectOutlook(Outlook $outlook): RedirectResponse
    {
        $outlook->disconnect(auth()->user());

        return back()->with('status', __('Outlook disconnected. The dashboard can no longer create drafts for you.'));
    }

    public function callback(SignInWithMicrosoftAction $signIn, Outlook $outlook): RedirectResponse
    {
        $returnTo = session()->pull('microsoft.connect_outlook');

        try {
            $microsoftUser = Socialite::driver('azure')->user();
        } catch (InvalidStateException) {
            return $returnTo && Auth::check()
                ? redirect($returnTo)->with('status', __('Connecting Outlook took too long. Please try again.'))
                : to_route('login')->withErrors(['microsoft' => __('Your sign-in expired. Please try again.')]);
        }

        if ($returnTo && Auth::check() && $microsoftUser instanceof OAuthUser) {
            if ($microsoftUser->getId() !== Auth::user()->microsoft_id) {
                return redirect($returnTo)->with('status', __('That was a different Microsoft account. Connect Outlook with your own.'));
            }

            $outlook->connect(Auth::user(), $microsoftUser->token, $microsoftUser->refreshToken, $microsoftUser->expiresIn);

            return redirect($returnTo)->with('status', __('Outlook connected. Drafts will appear in your Outlook Drafts folder.'));
        }

        $user = $signIn->execute($microsoftUser);

        if (! $user) {
            return to_route('login')->withErrors(['microsoft' => __('Only Formwerks accounts can sign in.')]);
        }

        Auth::login($user, remember: true);

        request()->session()->regenerate();

        return redirect()->intended(route('overview'));
    }
}
