{{-- Badges for an account's state. Expects $account. --}}
@php($locked = \App\Sso\Accounts\Lockout::isLocked($account))
@if (! $account->status_flag)<span class="badge">Disabled</span>@endif
@if ($locked)<span class="badge">Locked</span>@endif
@if ($account->must_change_password)<span class="badge">Must change password</span>@endif
@if ($account->status_flag && ! $locked && ! $account->must_change_password)<span class="badge ok">Active</span>@endif
