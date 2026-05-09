@component('mail::message')

# Verify Your Email Address

Thank you for registering. Click the button below to verify your email address and complete your registration.

@component('mail::button', ['url' => $url])
Verify Email
@endcomponent

This link will expire in **{{ $expiresIn }} minutes**.

If you did not create an account, no action is required — simply ignore this email.

@endcomponent
