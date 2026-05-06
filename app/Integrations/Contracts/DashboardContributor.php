<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for integrations that contribute named widgets,
 * counters, or status objects to the core dashboard.
 *
 * The dashboard view is hardcoded to read specific variable names
 * (lazadaPending, shopeeSyncStatus, openPos, etc.). Each integration
 * that ships dashboard widgets returns those keys here. Core's
 * DashboardController merges every contributor's array into the view
 * payload after seeding sensible nulls/zeros, so an extension being
 * disabled simply means its keys stay at the defaults.
 *
 * Integrations that don't add anything to the dashboard simply don't
 * implement this interface and are skipped by
 * IntegrationRegistry::dashboardContributors().
 */
interface DashboardContributor
{
    /**
     * Return data keyed by the dashboard view's expected variable names.
     *
     * @return array<string, mixed>
     */
    public function dashboardData(): array;
}
