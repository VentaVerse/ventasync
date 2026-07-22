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

    protected $fillable = ['name', 'description', 'active', 'created_by'];

    protected $casts = [
        'active'       => 'boolean',
        'last_used_at' => 'datetime',
    ];

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
