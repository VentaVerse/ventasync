<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

class ApiClient extends Model implements HasApiTokensContract
{
    use HasApiTokens;

    protected static function booted(): void
    {
        static::creating(function () {
            if ($full = \App\Plans\Quota::refusal('api_apps')) {
                throw new \App\Plans\PlanLimitReached($full);
            }
        });
    }

    protected $fillable = ['name', 'description', 'active', 'created_by', 'mcp_enabled', 'mcp_client', 'allowed_ips', 'calls_per_minute'];

    protected $hidden = ['token_secret'];

    protected $casts = [
        'token_secret'     => 'encrypted',
        'active'           => 'boolean',
        'mcp_enabled'      => 'boolean',
        'allowed_ips'      => 'array',
        'calls_per_minute' => 'integer',
        'last_used_at'     => 'datetime',
    ];

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

    public function hasScope(string $scope): bool
    {
        $token = $this->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            return $token->can($scope);
        }

        return in_array($scope, $this->grantedScopes(), true) || in_array('*', $this->grantedScopes(), true);
    }

    public function issueViewableToken(array $abilities, ?\DateTimeInterface $expiresAt): string
    {
        $plain = $this->createToken($this->name, $abilities, $expiresAt)->plainTextToken;

        $this->forceFill(['token_secret' => $plain])->save();

        return $plain;
    }

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
