@component('mail::message')

# Verify Your Email Address

Thank you for registering. You can verify your email address in either of two ways:

---

**Option 1 — Enter this code manually:**

@component('mail::panel')
# {{ $code }}
@endcomponent

This code expires in **{{ $expiresIn }} minutes**.

---

**Option 2 — Click the button to verify instantly:**

@component('mail::button', ['url' => $url])
Verify Email
@endcomponent

This link also expires in **{{ $expiresIn }} minutes**.

---

If you did not create an account, no action is required — simply ignore this email.

@endcomponent
