@component('mail::message')

# Reset Your Password

You requested a password reset. Click the button below to set a new password.

@component('mail::button', ['url' => $url, 'color' => 'red'])
Reset Password
@endcomponent

This link will expire in **{{ $expiresIn }} minutes**.

If you did not request a password reset, no action is required — your password will remain unchanged.

@endcomponent
