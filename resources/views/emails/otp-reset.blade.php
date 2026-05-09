@component('mail::message')

# Password Reset Request

You requested a password reset. Use the code below to set a new password.

@component('mail::panel')
# {{ $code }}
@endcomponent

This code will expire in **{{ $expiresIn }} minutes**.

If you did not request a password reset, no action is required — your password will remain unchanged.

@endcomponent
