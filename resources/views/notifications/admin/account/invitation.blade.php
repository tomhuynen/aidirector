@component('mail::message')

{{ __('Dear :name', ['name' => $user->name]) }},

{{ __('An account was created for you at **:app**.', [
'app' => config('app.title'),
]) }}

{{ __('Please use the button below to choose a password & start using your account.') }}

@component('mail::button', ['url' => $url])
{{ __('Activate account') }}
@endcomponent

@endcomponent
