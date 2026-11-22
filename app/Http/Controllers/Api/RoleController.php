<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Role;
use App\Sso\Activity;
use App\Sso\Api\ApiResponse;
use App\Sso\Api\Paging;
use App\Sso\Api\RequestFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    private const FIELDS = ['roleName' => 'role_name', 'roleDescription' => 'role_description'];

    public function index(): JsonResponse
    {
        return ApiResponse::success('Roles loaded.', Role::query()->orderBy('role_name')->get()->map(fn (Role $r) => $this->present($r))->all());
    }

    public function paginated(Request $request): JsonResponse
    {
        return ApiResponse::success('Roles loaded.', Paging::of(Role::query()->orderBy('role_name'), $request, fn (Role $r) => $this->present($r)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = RequestFields::pick($request, self::FIELDS);
        $name = $this->validName($data['role_name'] ?? null);
        $description = $this->validDescription($data['role_description'] ?? null);

        $role = Role::create(['role_name' => $name, 'role_description' => $description]);
        Activity::log($request->user(), "Created role {$role->role_name}", Activity::CREATE, 'Roles');

        return ApiResponse::success('Role created.', $this->present($role));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $role = (ctype_digit($id) && strlen($id) <= 18 ? Role::find($id) : null) ?? abort(404);
        $data = RequestFields::pick($request, self::FIELDS);
        $old = $role->role_name;
        $new = array_key_exists('role_name', $data) ? $this->validName($data['role_name'], $role) : $old;

        if ($new !== strtoupper($old) && in_array(strtoupper($old), Role::STANDARD, true)) {
            return ApiResponse::error('ROLE_PROTECTED', "Standard roles can't be renamed.", 400);
        }
        $description = array_key_exists('role_description', $data) ? $this->validDescription($data['role_description']) : $role->role_description;

        DB::transaction(function () use ($role, $old, $new, $description, $request) {
            $role->update(['role_name' => $new, 'role_description' => $description]);

            if ($new !== $old) {
                // An app's rule names the role, so it has to follow the rename.
                Client::query()->whereNotNull('allowed_roles')->get()->each(function (Client $client) use ($old, $new) {
                    $roles = $client->allowed_roles ?? [];
                    if (in_array($old, $roles, true)) {
                        $client->forceFill(['allowed_roles' => array_values(array_unique(array_map(fn ($r) => $r === $old ? $new : $r, $roles)))])->save();
                    }
                });
            }
            Activity::log($request->user(), $new === $old ? "Updated role {$new}" : "Renamed role {$old} to {$new}", Activity::UPDATE, 'Roles');
        });

        return ApiResponse::success('Role updated.', $this->present($role->refresh()));
    }

    /** Upper-cased; letters, digits and underscore; unique. */
    private function validName(mixed $value, ?Role $existing = null): string
    {
        $name = strtoupper(trim((string) $value));

        $error = match (true) {
            $name === '' => 'The role name is required.',
            strlen($name) > 100 => 'The role name is too long (at most 100 characters).',
            ! preg_match('/^[A-Z0-9_]+$/', $name) => 'Use letters, digits and underscores only.',
            Role::whereRaw('UPPER(role_name) = ?', [$name])->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists() => 'This role already exists.',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages(['roleName' => [$error]]);
        }

        return $name;
    }

    private function validDescription(mixed $value): ?string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) > 1000) {
            throw ValidationException::withMessages(['roleDescription' => ['This is too long (at most 1000 characters).']]);
        }

        return $text === '' ? null : $text;
    }

    /** @return array<string, mixed> */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'roleName' => $role->role_name,
            'roleDescription' => $role->role_description,
            'createdAt' => $role->created_at?->toIso8601String(),
        ];
    }
}
