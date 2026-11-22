<?php

namespace App\Sso\Api;

use stdClass;

/**
 * Removes secrets from request and response bodies before they reach the API audit trail.
 * JSON values under a sensitive key are replaced at any depth; bodies that are not JSON are not stored.
 */
class SensitiveDataMasker
{
    public const REDACTED = '***';
    public const NON_JSON_OMITTED = '[non-JSON body omitted]';
    public const MAX_LENGTH = 10000;

    /** Compared ignoring case, "_" and "-", so refresh_token equals refreshToken. */
    private const SENSITIVE = [
        'password', 'newpassword', 'currentpassword', 'oldpassword', 'confirmpassword',
        'token', 'accesstoken', 'refreshtoken', 'idtoken',
        'clientsecret', 'secret', 'authorization', 'temporarypassword',
    ];

    public function mask(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return $body;
        }

        $root = json_decode($body);
        if (! is_array($root) && ! $root instanceof stdClass) {
            return self::NON_JSON_OMITTED;
        }

        $json = json_encode($this->maskValue($root), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_substr((string) $json, 0, self::MAX_LENGTH);
    }

    private function maskValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $inner) {
                $value->{$key} = $this->isSensitive((string) $key) ? self::REDACTED : $this->maskValue($inner);
            }
        } elseif (is_array($value)) {
            return array_map(fn ($inner) => $this->maskValue($inner), $value);
        }

        return $value;
    }

    private function isSensitive(string $key): bool
    {
        return in_array(str_replace(['_', '-'], '', mb_strtolower($key)), self::SENSITIVE, true);
    }
}
