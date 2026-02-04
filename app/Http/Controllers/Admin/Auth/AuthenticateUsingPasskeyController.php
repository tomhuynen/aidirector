<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Actions\Passkeys\FindPasskeyToAuthenticateAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Session;
use Spatie\LaravelPasskeys\Events\PasskeyUsedToAuthenticateEvent;
use Spatie\LaravelPasskeys\Http\Requests\AuthenticateUsingPasskeysRequest;
use Spatie\LaravelPasskeys\Models\Passkey;
use Spatie\LaravelPasskeys\Support\Config;

class AuthenticateUsingPasskeyController
{
    public function __invoke(AuthenticateUsingPasskeysRequest $request)
    {
        $findAuthenticatableUsingPasskey = Config::getAction(
            'find_passkey',
            FindPasskeyToAuthenticateAction::class
        );

        $passkey = $findAuthenticatableUsingPasskey->execute(
            $request->get('start_authentication_response'),
            Session::get('passkey-authentication-options'),
        );

        if (! $passkey) {
            return $this->invalidPasskeyResponse();
        }

        /** @var Authenticatable $authenticatable */
        $authenticatable = $passkey->authenticatable;

        /** @phpstan-ignore booleanNot.alwaysFalse */
        if (! $authenticatable) {
            return $this->invalidPasskeyResponse();
        }

        $this->logInAuthenticatable($authenticatable, $request);

        $this->firePasskeyEvent($passkey, $request);

        return $this->validPasskeyResponse($request);
    }

    protected function logInAuthenticatable(Authenticatable $authenticatable, Request $request): self
    {
        auth()->login($authenticatable, $request->boolean('remember'));

        Session::regenerate();

        return $this;
    }

    protected function validPasskeyResponse(Request $request)
    {
        $url = Session::has('passkeys.redirect')
            ? Session::pull('passkeys.redirect')
            : Config::getRedirectAfterLogin();

        if ($request->expectsJson()) {
            return [
                'url' => $url,
            ];
        }

        return redirect($url);
    }

    protected function invalidPasskeyResponse(): Response
    {
        return response([
            'error' => __('Could not login using the given passkey.'),
        ], Response::HTTP_UNAUTHORIZED);
    }

    protected function firePasskeyEvent(Passkey $passkey, AuthenticateUsingPasskeysRequest $request): self
    {
        event(new PasskeyUsedToAuthenticateEvent($passkey, $request));

        return $this;
    }
}
