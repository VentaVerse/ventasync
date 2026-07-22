<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Services\ApiScopeRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Settings → API. Manages API Applications (external 3rd-party clients) and
 * their scoped Sanctum tokens: create with a Read/Write scope matrix and
 * optional expiry, rotate, and revoke. The plaintext token is shown once,
 * immediately after create/rotate, via a flashed session value.
 *
 * Read gated by view_api_clients|manage_api_clients; writes by
 * manage_api_clients (enforced at the route level).
 */
class ApiClientController extends Controller
{
    public function __construct(private readonly ApiScopeRegistry $scopeRegistry)
    {
    }

    public function index(Request $request)
    {
        $clients = ApiClient::query()
            ->with(['tokens' => fn ($q) => $q->latest()])
            ->latest()
            ->get()
            ->map(function (ApiClient $client) {
                $token = $client->tokens->first();

                return (object) [
                    'model'      => $client,
                    'scopes'     => $token->abilities ?? [],
                    'expires_at' => $token?->expires_at,
                    'last_used'  => $client->last_used_at,
                ];
            });

        return view('settings.api.index', [
            'clients' => $clients,
        ]);
    }

    /**
     * Dedicated "Add Application" page: name + scope matrix + expiry.
     */
    public function create(Request $request)
    {
        return view('settings.api.create', [
            'scopes' => $this->scopeRegistry->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:255'],
            'scopes'        => ['required', 'array', 'min:1'],
            'no_expiry'     => ['nullable', 'boolean'],
            'expires_at'    => ['nullable', 'date', 'after:today', 'required_without:no_expiry'],
        ]);

        $abilities = $this->resolveScopes($request);
        if (empty($abilities)) {
            return back()->withInput()->with('error', 'Select at least one permission.');
        }

        $expiresAt = $this->resolveExpiry($request);

        $client = ApiClient::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'active'      => true,
            'created_by'  => $request->user()->id,
        ]);

        $plain = $client->createToken($client->name, $abilities, $expiresAt)->plainTextToken;

        return redirect()->route('api_clients.index')
            ->with('status', 'API application created.')
            ->with('new_token', $plain)
            ->with('new_token_client', $client->name);
    }

    public function rotate(Request $request, ApiClient $apiClient)
    {
        $current = $apiClient->tokens()->latest()->first();
        $abilities = $current->abilities ?? ['*'];
        $expiresAt = $current?->expires_at;

        // Revoke every existing token, then issue a fresh one with the same
        // scopes + expiry policy. Old token immediately becomes invalid.
        $apiClient->tokens()->delete();
        $plain = $apiClient->createToken($apiClient->name, $abilities, $expiresAt)->plainTextToken;

        return redirect()->route('api_clients.index')
            ->with('status', 'Token rotated. The previous token no longer works.')
            ->with('new_token', $plain)
            ->with('new_token_client', $apiClient->name);
    }

    public function destroy(Request $request, ApiClient $apiClient)
    {
        $apiClient->tokens()->delete();
        $apiClient->delete();

        return redirect()->route('api_clients.index')->with('status', 'API application revoked.');
    }

    /**
     * Turn the posted scope matrix (scopes[resource][action]=1) into a flat
     * list of "resource:action" abilities, validated against the catalog.
     */
    private function resolveScopes(Request $request): array
    {
        $catalog = $this->scopeRegistry->all();
        $posted = (array) $request->input('scopes', []);
        $abilities = [];

        foreach ($posted as $resource => $actions) {
            if (! isset($catalog[$resource])) {
                continue;
            }
            foreach ((array) $actions as $action => $on) {
                if (! $on) {
                    continue;
                }
                if (in_array($action, $catalog[$resource]['actions'], true)) {
                    $abilities[] = "{$resource}:{$action}";
                }
            }
        }

        return array_values(array_unique($abilities));
    }

    private function resolveExpiry(Request $request): ?Carbon
    {
        if ($request->boolean('no_expiry')) {
            return null;
        }

        $date = $request->input('expires_at');

        return $date ? Carbon::parse($date)->endOfDay() : null;
    }
}
