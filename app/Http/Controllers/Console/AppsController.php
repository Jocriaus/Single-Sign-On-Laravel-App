<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Role;
use App\Sso\Apps\ClientRegistrar;
use App\Sso\OAuth\Scopes;
use Illuminate\Http\Request;

class AppsController extends Controller
{
    public function __construct(private ClientRegistrar $registrar) {}

    public function index()
    {
        return view('admin.apps', ['apps' => Client::orderBy('name')->get()]);
    }

    public function create()
    {
        return view('admin.app-new', ['roleChoices' => $this->roleChoices()]);
    }

    public function store(Request $request)
    {
        $created = $this->registrar->register($this->data($request));

        return $this->secretPage($created['client'], $created['secret'], true);
    }

    public function show(string $client)
    {
        return view('admin.app', ['app' => $this->find($client), 'roleChoices' => $this->roleChoices()]);
    }

    public function update(Request $request, string $client)
    {
        $app = $this->registrar->update($this->find($client), $this->data($request));

        return redirect()->route('admin.apps.show', $app->id)->with('status', 'Saved.');
    }

    public function resetSecret(Request $request, string $client)
    {
        $app = $this->find($client);
        $this->confirmed($request);

        return $this->secretPage($app, $this->registrar->resetSecret($app), false);
    }

    public function destroy(Request $request, string $client)
    {
        $app = $this->find($client);
        $this->confirmed($request);
        $this->registrar->remove($app);

        return redirect()->route('admin.apps')->with('status', 'App removed.');
    }

    private function find(string $id): Client
    {
        return Client::find($id) ?? abort(response()->view('admin.not-found', [
            'message' => "That app doesn't exist. It may have been removed.",
        ], 404));
    }

    private function confirmed(Request $request): void
    {
        $request->validate(['confirm' => 'accepted'], ['confirm.accepted' => 'Tick the box to confirm.']);
    }

    /** The secret goes only into this response, never into the session. */
    private function secretPage(Client $app, string $secret, bool $isNew)
    {
        return response()
            ->view('admin.app-secret', ['app' => $app, 'secret' => $secret, 'isNew' => $isNew])
            ->header('Cache-Control', 'no-store, private');
    }

    /** @return list<string> */
    private function roleChoices(): array
    {
        return Role::orderBy('role_name')->pluck('role_name')->all();
    }

    private function data(Request $request): array
    {
        $roles = $request->input('access_mode') === 'roles' ? (array) $request->input('allowed_roles', []) : [];

        return [
            'name' => $request->input('name'),
            'redirect_uris' => $request->input('redirect_uris'),
            'post_logout_redirect_uris' => $request->input('post_logout_redirect_uris'),
            'backchannel_logout_uri' => $request->input('backchannel_logout_uri'),
            'scopes' => $request->boolean('may_administer') ? [...Scopes::DEFAULT, Scopes::ADMIN] : Scopes::DEFAULT,
            'allowed_roles' => $roles,
            'require_pkce' => $request->boolean('require_pkce'),
        ];
    }
}
