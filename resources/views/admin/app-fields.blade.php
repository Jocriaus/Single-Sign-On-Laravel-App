{{-- The app form fields, shared by "Register app" and the app page. Expects $roleChoices and optionally $app. --}}
@php
    $app = $app ?? null;
    $typed = old('name') !== null;
    $mayAdminister = $typed ? (bool) old('may_administer') : in_array('sso.admin', $app?->scopes ?? [], true);
    $requirePkce = $typed ? (bool) old('require_pkce') : ($app ? $app->require_pkce : true);
    $accessMode = old('access_mode', $app?->allowed_roles ? 'roles' : 'everyone');
    $chosenRoles = (array) old('allowed_roles', $app?->allowed_roles ?? []);
    $redirects = old('redirect_uris', implode("\n", $app?->redirect_uris ?? []));
    $logoutRedirects = old('post_logout_redirect_uris', implode("\n", $app?->post_logout_redirect_uris ?? []));
@endphp

<label for="name">Name</label>
<p class="help">Shown on the login page: "You're signing in to …".</p>
<input id="name" name="name" type="text" value="{{ old('name', $app?->name) }}" required maxlength="255">
@error('name')<p class="field-error" role="alert">{{ $message }}</p>@enderror

<label for="redirect_uris">Redirect URIs</label>
<p class="help">Where the SSO sends users back after sign-in, one per line.</p>
<textarea id="redirect_uris" name="redirect_uris" required>{{ $redirects }}</textarea>
@error('redirect_uris')<p class="field-error" role="alert">{{ $message }}</p>@enderror

<label for="post_logout_redirect_uris">Sign-out return addresses</label>
<p class="help">Where users may land after signing out, one per line. Usually the app's home page.</p>
<textarea id="post_logout_redirect_uris" name="post_logout_redirect_uris">{{ $logoutRedirects }}</textarea>
@error('post_logout_redirect_uris')<p class="field-error" role="alert">{{ $message }}</p>@enderror

<label for="backchannel_logout_uri">Single-logout address</label>
<p class="help">Optional. The SSO tells the app here when a user signs out, so the app ends its session too.</p>
<input id="backchannel_logout_uri" name="backchannel_logout_uri" type="url" value="{{ old('backchannel_logout_uri', $app?->backchannel_logout_uri) }}">
@error('backchannel_logout_uri')<p class="field-error" role="alert">{{ $message }}</p>@enderror

<fieldset>
    <legend>What the app may ask for</legend>
    <p class="help">Every app can ask for openid, profile and email.</p>
    <label class="check"><input type="checkbox" name="may_administer" value="1" @checked($mayAdminister)>
        <span>May administer the SSO (sso.admin). Only for admin tools.</span></label>
    <label class="check"><input type="checkbox" name="require_pkce" value="1" @checked($requirePkce)>
        <span>Require PKCE. Switch off only for an app that cannot use it.</span></label>
    @error('scopes')<p class="field-error" role="alert">{{ $message }}</p>@enderror
</fieldset>

<fieldset>
    <legend>Who can sign in</legend>
    <label class="check"><input type="radio" name="access_mode" value="everyone" @checked($accessMode !== 'roles')>
        <span>Everyone with an active account</span></label>
    <label class="check"><input type="radio" name="access_mode" value="roles" @checked($accessMode === 'roles')>
        <span>Only people with these roles</span></label>
    <p class="help">Others see "You don't have access" at the SSO and never reach the app. STUDENT and EMPLOYEE are
        given automatically to everyone with a student or employee number.</p>
    @foreach ($roleChoices as $role)
        <label class="check"><input type="checkbox" name="allowed_roles[]" value="{{ $role }}" @checked(in_array($role, $chosenRoles, true))>
            <span>{{ $role }}</span></label>
    @endforeach
    @error('allowed_roles')<p class="field-error" role="alert">{{ $message }}</p>@enderror
</fieldset>
