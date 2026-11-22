<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Sso\Api\ApiResponse;
use App\Sso\Api\Paging;
use App\Sso\Api\RequestFields;
use App\Sso\Apps\ClientRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Thin JSON over ClientRegistrar: every app rule lives there. */
class ClientController extends Controller
{
    private const FIELDS = [
        'name' => 'name', 'redirectUris' => 'redirect_uris', 'postLogoutRedirectUris' => 'post_logout_redirect_uris',
        'scopes' => 'scopes', 'backchannelLogoutUri' => 'backchannel_logout_uri', 'allowedRoles' => 'allowed_roles',
        'requirePkce' => 'require_pkce',
    ];

    private const LISTS = ['redirectUris', 'postLogoutRedirectUris', 'scopes', 'allowedRoles'];

    public function __construct(private ClientRegistrar $registrar) {}

    public function store(Request $request): JsonResponse
    {
        $registered = $this->registrar->register(RequestFields::pick($request, self::FIELDS, self::LISTS));

        return ApiResponse::success('App registered.', [
            'clientId' => $registered['client']->id,
            'clientSecret' => $registered['secret'],
            'clientName' => $registered['client']->name,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success('Apps loaded.', Client::query()->orderBy('name')->orderBy('id')->get()->map(fn (Client $c) => $this->present($c))->all());
    }

    public function paginated(Request $request): JsonResponse
    {
        $query = Client::query()->orderBy('name')->orderBy('id');

        return ApiResponse::success('Apps loaded.', Paging::of($query, $request, fn (Client $c) => $this->present($c)));
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success('App loaded.', $this->present($this->find($id)));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->registrar->remove($this->find($id));

        return ApiResponse::success('App removed.');
    }

    private function find(string $id): Client
    {
        return Client::findById($id) ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function present(Client $client): array
    {
        return [
            'clientId' => $client->id,
            'clientName' => $client->name,
            'redirectUris' => $client->redirect_uris ?? [],
            'postLogoutRedirectUris' => $client->post_logout_redirect_uris ?? [],
            'scopes' => $client->scopes ?? [],
            'backchannelLogoutUri' => $client->backchannel_logout_uri,
            'allowedRoles' => $client->allowed_roles ?? [],
            'requirePkce' => (bool) $client->require_pkce,
            'createdAt' => $client->created_at?->toIso8601String(),
        ];
    }
}
