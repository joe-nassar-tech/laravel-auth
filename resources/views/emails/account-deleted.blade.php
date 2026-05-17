@component('mail::message')

# Account scheduled for deletion

We received a request to delete your account. It will be permanently removed on **{{ $scheduledPurgeAt->toFormattedDateString() }}** — **{{ $graceDays }} days** from now.

## Changed your mind?

Simply **log in again** before that date and your account will be restored automatically. No action is required if you want to proceed with deletion — just don't log in.

After the grace period passes, your data will be anonymised and cannot be recovered.

If you did not request this, please log in immediately to restore your account and change your password.

Thanks,<br>
{{ config('app.name') }}

@endcomponent
