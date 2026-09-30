<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * A 3rd-party application granted scoped API access to the ERP.
 *
 * Owns Sanctum tokens whose abilities encode granted "resource:action"
 * scopes (see config/api_scopes.php). Auth resolves $request->user() to an
 * ApiClient for external API calls; route access is enforced by the
 * `scope` middleware via tokenCan().
 */
class ApiClient extends Model implements HasApiTokensContract
{
    use HasApiTokens;

    protected $fillable = ['name', 'description', 'active', 'created_by', 'mcp_enabled', 'assistant', 'allowed_ips', 'calls_per_minute'];

    /** The readable copy of the current token never leaves in an array or JSON dump. */
    protected $hidden = ['token_secret'];

    protected $casts = [
        'token_secret'     => 'encrypted',
        'active'           => 'boolean',
        'mcp_enabled'      => 'boolean',
        'allowed_ips'      => 'array',
        'calls_per_minute' => 'integer',
        'last_used_at'     => 'datetime',
    ];

    /**
     * Whether a request from this address may use the application. An
     * empty list means anywhere; entries may be single addresses or CIDR
     * ranges. Applies to the API and to MCP alike.
     */
    public function allowsIp(?string $ip): bool
    {
        $list = array_values(array_filter((array) ($this->allowed_ips ?? []), 'is_string'));
        if ($list === []) {
            return true;
        }
        if ($ip === null || $ip === '') {
            return false;
        }

        return \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $list);
    }

    /**
     * Whether the current token (or, with no token in play, any token)
     * carries a scope. The MCP tools ask this before they run.
     */
    public function hasScope(string $scope): bool
    {
        $token = $this->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            return $token->can($scope);
        }

        return in_array($scope, $this->grantedScopes(), true) || in_array('*', $this->grantedScopes(), true);
    }

    /**
     * Issue a token and keep an encrypted copy of it, so it can be viewed
     * again. Returns the plaintext.
     */
    public function issueViewableToken(array $abilities, ?\DateTimeInterface $expiresAt): string
    {
        $plain = $this->createToken($this->name, $abilities, $expiresAt)->plainTextToken;

        $this->forceFill(['token_secret' => $plain])->save();

        return $plain;
    }

    /**
     * The current token in plaintext, or null when none is kept or the kept
     * copy no longer matches a live token of this application.
     */
    public function viewableToken(): ?string
    {
        $plain = $this->token_secret;
        if (! is_string($plain) || $plain === '') {
            return null;
        }

        $token = \Laravel\Sanctum\PersonalAccessToken::findToken($plain);
        if (! $token || $token->tokenable_type !== $this->getMorphClass() || (int) $token->tokenable_id !== (int) $this->getKey()) {
            return null;
        }

        return $plain;
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    /** All scopes currently granted across this client's active tokens. */
    public function grantedScopes(): array
    {
        return $this->tokens()
            ->get()
            ->flatMap(fn ($token) => $token->abilities ?? [])
            ->unique()
            ->values()
            ->all();
    }
}
