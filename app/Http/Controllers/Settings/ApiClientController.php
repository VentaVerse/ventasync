<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
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
            'scopes'        => ['required', 'array', 'min:1'],
            'no_expiry'     => ['nullable', 'boolean'],
            'expires_at'    => ['nullable', 'date', 'after:today', 'required_without:no_expiry'],
        ] + $this->mcpRules());

        $abilities = $this->resolveScopes($request);
        if (empty($abilities)) {
            return back()->withInput()->with('error', 'Select at least one permission.');
        }

        $expiresAt = $this->resolveExpiry($request);

        $client = ApiClient::create([
            'name'       => $data['name'],
            'active'     => true,
            'created_by' => $request->user()->id,
        ] + $this->mcpFields($request));

        $plain = $client->createToken($client->name, $abilities, $expiresAt)->plainTextToken;

        return redirect()->route('api_clients.index')
            ->with('status', 'API application created.')
            ->with('new_token', $plain)
            ->with('new_token_client', $client->name);
    }

    /**
     * Edit page: adjust name/description, scopes, and expiry of an existing
     * application. Editing scopes updates the current token's abilities in
     * place — the token value does not change.
     */
    public function edit(Request $request, ApiClient $apiClient)
    {
        $token = $apiClient->tokens()->latest()->first();

        return view('settings.api.edit', [
            'client'    => $apiClient,
            'scopes'    => $this->scopeRegistry->all(),
            'current'   => $token->abilities ?? [],
            'expiresAt' => $token?->expires_at,
        ]);
    }

    public function update(Request $request, ApiClient $apiClient)
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'scopes'     => ['required', 'array', 'min:1'],
            'no_expiry'  => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:today', 'required_without:no_expiry'],
        ] + $this->mcpRules());

        $abilities = $this->resolveScopes($request);
        if (empty($abilities)) {
            return back()->withInput()->with('error', 'Select at least one permission.');
        }

        $expiresAt = $this->resolveExpiry($request);

        $apiClient->update(['name' => $data['name']] + $this->mcpFields($request));

        // Update the existing token's scopes + expiry in place (token value
        // unchanged). If somehow there is no token, issue one and reveal it.
        $token = $apiClient->tokens()->latest()->first();
        if ($token) {
            $token->abilities = $abilities;
            $token->expires_at = $expiresAt;
            $token->save();

            return redirect()->route('api_clients.index')->with('status', 'API application updated.');
        }

        $plain = $apiClient->createToken($apiClient->name, $abilities, $expiresAt)->plainTextToken;

        return redirect()->route('api_clients.index')
            ->with('status', 'API application updated.')
            ->with('new_token', $plain)
            ->with('new_token_client', $apiClient->name);
    }

    public function rotate(Request $request, ApiClient $apiClient)
    {
        $current = $apiClient->tokens()->latest()->first();
        if (! $current) {
            return redirect()->route('api_clients.index')
                ->with('error', 'No existing token to rotate. Edit the application to set its scopes and issue a token first.');
        }

        $abilities = $current->abilities ?? [];
        if (empty($abilities) || in_array('*', $abilities, true)) {
            return redirect()->route('api_clients.index')
                ->with('error', 'This application has no explicit scopes. Edit it to assign scopes before rotating.');
        }

        $expiresAt = $current->expires_at;

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

    /**
     * The AI assistant fields (#131). Allowed IPs arrive one per line and
     * may be single addresses or CIDR ranges; the per-minute cap is blank
     * for none, which keeps the API's no-rate-limit decision.
     *
     * @return array<string, array<int, mixed>>
     */
    private function mcpRules(): array
    {
        return [
            'mcp_enabled'      => ['nullable', 'boolean'],
            'allowed_ips'      => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) {
                foreach ($this->splitIps((string) $value) as $ip) {
                    if (! self::looksLikeIpOrRange($ip)) {
                        $fail("\"{$ip}\" is not an IP address or a CIDR range.");
                    }
                }
            }],
            'calls_per_minute' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }

    /** @return array<string, mixed> */
    private function mcpFields(Request $request): array
    {
        $ips = $this->splitIps((string) $request->input('allowed_ips', ''));

        return [
            'mcp_enabled'      => $request->boolean('mcp_enabled'),
            'allowed_ips'      => $ips === [] ? null : $ips,
            'calls_per_minute' => $request->filled('calls_per_minute') ? (int) $request->integer('calls_per_minute') : null,
        ];
    }

    /** @return list<string> */
    private function splitIps(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($p) => $p !== '')));
    }

    private static function looksLikeIpOrRange(string $ip): bool
    {
        [$addr, $bits] = array_pad(explode('/', $ip, 2), 2, null);
        if (filter_var($addr, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($addr, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
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
