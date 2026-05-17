@component('mail::message')

# Your account has been removed

The grace period for your deletion request has passed. Your account and personal data have been permanently anonymised.

If you wish to use {{ config('app.name') }} again, you are welcome to register a new account.

Thanks,<br>
{{ config('app.name') }}

@endcomponent
