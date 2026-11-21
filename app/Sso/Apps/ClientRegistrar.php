<?php

namespace App\Sso\Apps;

use App\Models\Client;
use App\Models\Role;
use App\Sso\Activity;
use App\Sso\OAuth\Scopes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientRegistrar
{
    /**
     * @return array{client: Client, secret: string}
     */
    public function register(array $data, ?string $id = null, ?string $secret = null): array
    {
        $attributes = $this->attributes($data);
        $secret ??= Str::random(48);

        $client = new Client($attributes + [
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        if ($id !== null) {
            $client->id = $id;
        }
        $client->secret = $secret;
        $client->save();

        Activity::log(auth()->user(), "Registered app {$client->name}", Activity::CREATE, 'Apps');

        return ['client' => $client, 'secret' => $secret];
    }

    public function update(Client $client, array $data): Client
    {
        $client->forceFill($this->attributes($data))->save();
        Activity::log(auth()->user(), "Updated app {$client->name}", Activity::UPDATE, 'Apps');

        return $client;
    }

    public function resetSecret(Client $client): string
    {
        $secret = Str::random(48);
        $client->secret = $secret;
        $client->save();
        Activity::log(auth()->user(), "Reset the secret of app {$client->name}", Activity::UPDATE, 'Apps');

        return $secret;
    }

    public function remove(Client $client): void
    {
        DB::transaction(function () use ($client) {
            $tokenIds = DB::table('oauth_access_tokens')->where('client_id', $client->id)->pluck('id');
            foreach ($tokenIds->chunk(500) as $chunk) {
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $chunk)->delete();
            }
            DB::table('oauth_access_tokens')->where('client_id', $client->id)->delete();
            DB::table('oauth_auth_codes')->where('client_id', $client->id)->delete();
            $client->delete();
        });
        Activity::log(auth()->user(), "Removed app {$client->name}", Activity::DELETE, 'Apps');
    }

    private function attributes(array $data): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            $errors['name'] = 'The name is required and at most 255 characters.';
        }

        $redirects = $this->urls($data['redirect_uris'] ?? [], 'redirect_uris', $errors);
        if (! $redirects && ! isset($errors['redirect_uris'])) {
            $errors['redirect_uris'] = 'At least one redirect address is required.';
        }
        $logoutRedirects = $this->urls($data['post_logout_redirect_uris'] ?? [], 'post_logout_redirect_uris', $errors);

        $backchannel = trim((string) ($data['backchannel_logout_uri'] ?? ''));
        if ($backchannel === '') {
            $backchannel = null;
        } elseif (! $this->validUrl($backchannel)) {
            $errors['backchannel_logout_uri'] = 'Must be an absolute http or https address without a fragment.';
        }

        $scopes = $this->list($data['scopes'] ?? []) ?: Scopes::DEFAULT;
        if (array_diff($scopes, Scopes::ALLOWED)) {
            $errors['scopes'] = 'Unknown scope. Allowed: '.implode(', ', Scopes::ALLOWED).'.';
        }
        $scopes = array_values(array_unique(['openid', ...array_diff($scopes, ['openid'])]));

        $roles = array_values(array_unique(array_map('strtoupper', $this->list($data['allowed_roles'] ?? []))));
        if ($roles && Role::whereIn('role_name', $roles)->count() !== count($roles)) {
            $errors['allowed_roles'] = 'Unknown role.';
        }

        if ($errors) {
            throw ValidationException::withMessages(array_map(fn ($m) => [$m], $errors));
        }

        return [
            'name' => $name,
            'redirect_uris' => $redirects,
            'post_logout_redirect_uris' => $logoutRedirects ?: null,
            'backchannel_logout_uri' => $backchannel,
            'scopes' => $scopes,
            'allowed_roles' => $roles ?: null,
            'require_pkce' => filter_var($data['require_pkce'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value);
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            (array) $value
        ), fn ($v) => $v !== '')));
    }

    /** @return list<string> */
    private function urls(mixed $value, string $key, array &$errors): array
    {
        $urls = $this->list($value);
        foreach ($urls as $url) {
            if (! $this->validUrl($url)) {
                $errors[$key] = "Must be absolute http or https addresses without a fragment: {$url}";
            }
        }

        return $urls;
    }

    private function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! empty($parts['host'])
            && ! str_contains($url, '#');
    }
}
