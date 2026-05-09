@component('mail::message')

# Verify Your Email Address

Thank you for registering. Use the code below to verify your email address.

@component('mail::panel')
# {{ $code }}
@endcomponent

This code will expire in **{{ $expiresIn }} minutes**.

If you did not create an account, no action is required — simply ignore this email.

@endcomponent
