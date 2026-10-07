<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Services\ActivityLogger;
use App\Services\ApiScopeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ApiClientController extends Controller
{
    public function __construct(private readonly ApiScopeRegistry $scopeRegistry)
    {
    }

    public function index(Request $request)
    {
        $clients = ApiClient::query()
            ->where('mcp_enabled', false)
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
        ] + \App\Support\Api\ClientLimits::rules());

        $abilities = $this->resolveScopes($request);
        if (empty($abilities)) {
            return back()->withInput()->with('error', 'Select at least one permission.');
        }

        $expiresAt = $this->resolveExpiry($request);

        [$client, $plain] = DB::transaction(function () use ($data, $request, $abilities, $expiresAt) {
            $client = ApiClient::create([
                'name'       => $data['name'],
                'active'     => true,
                'created_by' => $request->user()->id,
            ] + ['mcp_enabled' => false] + \App\Support\Api\ClientLimits::fields($request));

            return [$client, $client->issueViewableToken($abilities, $expiresAt)];
        });

        return redirect()->route('api_clients.index')
            ->with('status', 'API application created.')
            ->with('new_token', $plain)
            ->with('new_token_client', $client->name);
    }

    public function edit(Request $request, ApiClient $apiClient)
    {
        $this->notAnAssistant($apiClient);
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
        $this->notAnAssistant($apiClient);
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'scopes'     => ['required', 'array', 'min:1'],
            'no_expiry'  => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:today', 'required_without:no_expiry'],
        ] + \App\Support\Api\ClientLimits::rules());

        $abilities = $this->resolveScopes($request);
        if (empty($abilities)) {
            return back()->withInput()->with('error', 'Select at least one permission.');
        }

        $expiresAt = $this->resolveExpiry($request);

        $plain = DB::transaction(function () use ($apiClient, $data, $request, $abilities, $expiresAt) {
            $apiClient->update(['name' => $data['name']] + ['mcp_enabled' => false] + \App\Support\Api\ClientLimits::fields($request));

            $token = $apiClient->tokens()->latest()->first();
            if ($token) {
                $token->abilities = $abilities;
                $token->expires_at = $expiresAt;
                $token->save();

                return null;
            }

            return $apiClient->issueViewableToken($abilities, $expiresAt);
        });

        if ($plain === null) {
            return redirect()->route('api_clients.index')->with('status', 'API application updated.');
        }

        return redirect()->route('api_clients.index')
            ->with('status', 'API application updated.')
            ->with('new_token', $plain)
            ->with('new_token_client', $apiClient->name);
    }

    public function rotate(Request $request, ApiClient $apiClient)
    {
        $this->notAnAssistant($apiClient);
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

        $plain = DB::transaction(function () use ($apiClient, $abilities, $expiresAt) {
            $apiClient->tokens()->delete();

            return $apiClient->issueViewableToken($abilities, $expiresAt);
        });

        return redirect()->route('api_clients.index')
            ->with('status', 'Token rotated. The previous token no longer works.')
            ->with('new_token', $plain)
            ->with('new_token_client', $apiClient->name);
    }

    public function token(Request $request, ApiClient $apiClient): JsonResponse
    {
        $this->notAnAssistant($apiClient);

        $plain = $apiClient->viewableToken();
        if ($plain === null) {
            return response()->json([
                'ok' => false,
                'message' => 'This token was issued before tokens could be viewed again. Rotate it to get one that can.',
            ], 404)->header('Cache-Control', 'no-store');
        }

        ActivityLogger::log('api_client.token_viewed', 'api_client', $apiClient->id, $apiClient->name);

        return response()->json(['ok' => true, 'token' => $plain])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, ApiClient $apiClient)
    {
        $this->notAnAssistant($apiClient);
        $apiClient->tokens()->delete();
        $apiClient->delete();

        return redirect()->route('api_clients.index')->with('status', 'API application revoked.');
    }

    private function resolveScopes(Request $request): array
    {
        $catalog = $this->scopeRegistry->all();
        $posted = (array) $request->input('scopes', []);
        $abilities = [];

        foreach ($posted as $resource => $actions) {
            if (! isset($catalog[$resource])) {
                continue;
            }
            if (is_string($actions)) {
                $actions = match ($actions) {
                    'read'  => ['read' => 1],
                    'write' => ['read' => 1, 'write' => 1],
                    default => [],
                };
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

    private function notAnAssistant(ApiClient $apiClient): void
    {
        abort_if((bool) $apiClient->mcp_enabled, 404);
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
