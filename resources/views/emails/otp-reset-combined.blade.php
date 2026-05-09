@component('mail::message')

# Reset Your Password

You requested a password reset. You can proceed in either of two ways:

---

**Option 1 — Enter this code manually:**

@component('mail::panel')
# {{ $code }}
@endcomponent

This code expires in **{{ $expiresIn }} minutes**.

---

**Option 2 — Click the button to proceed instantly:**

@component('mail::button', ['url' => $url, 'color' => 'red'])
Reset Password
@endcomponent

This link also expires in **{{ $expiresIn }} minutes**.

---

If you did not request a password reset, no action is required — your password will remain unchanged.

@endcomponent
