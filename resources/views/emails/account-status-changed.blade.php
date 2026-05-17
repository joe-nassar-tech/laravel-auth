@component('mail::message')

# Your account status has changed

Your account status was changed from **{{ $previousStatus }}** to **{{ $newStatus }}**.

@if ($reason)
**Reason:** {{ $reason }}
@endif

@if ($newStatus === 'disabled' || $newStatus === 'suspended')
You will not be able to log in until this is reversed. If you believe this is a mistake, please contact support.
@elseif ($newStatus === 'active')
You can now log in as normal.
@endif

Thanks,<br>
{{ config('app.name') }}

@endcomponent
