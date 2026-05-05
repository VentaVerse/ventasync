<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationRegistry;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function index(Request $request, IntegrationRegistry $registry)
    {
        $cards = $registry->visibleCards($request->user());

        return view('integrations.index', compact('cards'));
    }

    /**
     * Dedicated sub-page for one module — lists all configured stores
     * with breathing room. Only useful for multi-store modules; for
     * single-store modules it just shows the same card content.
     */
    public function module(Request $request, IntegrationRegistry $registry, string $module)
    {
        $card = collect($registry->visibleCards($request->user()))
            ->firstWhere('id', $module);

        if ($card === null) {
            abort(404);
        }

        return view('integrations.module', compact('card'));
    }

    public function orders(Request $request, IntegrationRegistry $registry)
    {
        $user = $request->user();
        $tabs = $registry->visibleOrderTabs($user);

        // Empty state — no fulfillable surfaces visible to this user
        if (empty($tabs)) {
            return view('integrations.orders', [
                'hasMarketplaces' => false,
                'topProducts' => [],
                'recentOrders' => [],
            ]);
        }

        // Combined top-products leaderboard. Each tab returns its own top
        // products (scoped per store for multi-store marketplaces); we merge
        // by SKU, sum qty + revenue, and remember which tabs contributed so
        // the leaderboard row can show small per-tab dots next to the SKU.
        $merged = [];
        foreach ($tabs as $tab) {
            foreach ($tab->topProductsToday(10) as $tp) {
                $key = $tp->sku;
                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'sku' => $tp->sku,
                        'name' => $tp->name,
                        'imageUrl' => $tp->imageUrl,
                        'qtySold' => 0,
                        'revenue' => 0.0,
                        'contributors' => [],
                    ];
                }
                $merged[$key]['qtySold'] += $tp->qtySold;
                $merged[$key]['revenue'] += $tp->revenue;
                if ($tp->imageUrl && empty($merged[$key]['imageUrl'])) {
                    $merged[$key]['imageUrl'] = $tp->imageUrl;
                }
                $merged[$key]['contributors'][] = [
                    'label' => $tab->label,
                    'accent' => $tab->accent,
                    'qty' => $tp->qtySold,
                ];
            }
        }
        usort($merged, fn ($a, $b) => $b['qtySold'] <=> $a['qtySold']);
        $topProducts = array_slice(array_values($merged), 0, 10);

        // Combined recent activity feed — newest first across all tabs.
        $recent = [];
        foreach ($tabs as $tab) {
            foreach ($tab->recentOrders(5) as $ro) {
                $recent[] = [
                    'order' => $ro,
                    'tab' => $tab,
                ];
            }
        }
        usort($recent, function ($a, $b) {
            $aTs = $a['order']->orderedAt?->getTimestamp() ?? 0;
            $bTs = $b['order']->orderedAt?->getTimestamp() ?? 0;
            return $bTs <=> $aTs;
        });
        $recentOrders = array_slice($recent, 0, 15);

        return view('integrations.orders', [
            'hasMarketplaces' => true,
            'topProducts' => $topProducts,
            'recentOrders' => $recentOrders,
        ]);
    }
}
