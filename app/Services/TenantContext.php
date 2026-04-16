<?php

namespace App\Services;

use App\Models\ModuleLicense;
use RuntimeException;

/**
 * Runtime tenant/product context resolved from the active core license.
 *
 * Intentionally named operationally ("tenant", not "license"). Downstream services
 * (marketplace adapters, cache keyspace, queue routing) depend on slug() returning
 * a non-empty string. If the core license is missing or revoked, slug() throws —
 * the call site fails in place rather than silently no-op'ing.
 */
class TenantContext
{
    public function __construct(
        private readonly ?ModuleLicense $license,
    ) {}

    /**
     * Product slug from the active license. Throws when absent so callers fail
     * loudly and the failure is grep-able instead of silently degrading.
     */
    public function slug(): string
    {
        if ($this->license === null) {
            throw new RuntimeException('Tenant slug requested before context is ready.');
        }
        $slug = (string) ($this->license->metadata['product'] ?? '');
        if ($slug === '') {
            throw new RuntimeException('Tenant slug is empty in license metadata.');
        }
        return $slug;
    }

    public function slugOrNull(): ?string
    {
        try {
            return $this->slug();
        } catch (RuntimeException $e) {
            return null;
        }
    }

    public function isTrial(): bool
    {
        return $this->license?->isTrial() ?? false;
    }

    public function isReady(): bool
    {
        return $this->license !== null && $this->license->isActive();
    }

    public function maintenanceExpired(): bool
    {
        return $this->license?->maintenanceExpired() ?? true;
    }

    public function maintenanceExpiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->license?->maintenance_expires_at;
    }

    public function license(): ?ModuleLicense
    {
        return $this->license;
    }
}
