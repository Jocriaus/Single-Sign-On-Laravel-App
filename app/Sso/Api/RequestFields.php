<?php

namespace App\Sso\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Maps camelCase request JSON to the snake_case data the services take, and their error keys back. */
final class RequestFields
{
    /** Service keys whose camelCase name is not simply the camel form. */
    private const RENAMED = ['student_no' => 'studentNumber', 'employee_no' => 'employeeNumber'];

    /**
     * @param  array<string, string>  $map  request field => service key
     * @param  list<string>  $lists  request fields that may also be arrays of text
     * @return array<string, mixed>
     */
    public static function pick(Request $request, array $map, array $lists = []): array
    {
        $data = [];
        $errors = [];

        foreach ($map as $field => $key) {
            if (! $request->exists($field)) {
                continue;
            }
            $value = $request->input($field);
            $allowed = is_scalar($value) || $value === null
                || (in_array($field, $lists, true) && is_array($value) && array_is_list($value) && collect($value)->every(fn ($v) => is_scalar($v)));

            if ($allowed) {
                $data[$key] = $value;
            } else {
                $errors[$field] = ['This has the wrong type.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /** @param  array<string, list<string>>  $errors */
    public static function rekey(array $errors): array
    {
        $out = [];
        foreach ($errors as $key => $messages) {
            $out[self::RENAMED[$key] ?? Str::camel($key)] = $messages;
        }

        return $out;
    }
}
