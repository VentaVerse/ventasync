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

    public function orders(Request $request, IntegrationRegistry $registry)
    {
        $user = $request->user();
        $cards = collect($registry->visibleCards($user))->keyBy('id');
        $providers = $registry->visibleSummaryProviders($user);

        // Empty state — no marketplaces visible to this user
        if ($cards->isEmpty()) {
            return view('integrations.orders', [
                'hasMarketplaces' => false,
                'topProducts' => [],
                'recentOrders' => [],
            ]);
        }

        // Combined top-products leaderboard. Each provider returns its own top
        // products; we merge by SKU, sum qty + revenue, and remember which
        // marketplaces contributed so the leaderboard row can show small
        // marketplace dots next to the SKU.
        $merged = [];
        foreach ($providers as $provider) {
            $card = $cards->get($provider->integrationCard()?->id ?? '');
            if ($card === null) {
                continue;
            }
            foreach ($provider->topProductsToday(10) as $tp) {
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
                    'label' => $card->name,
                    'accent' => $card->accent,
                    'qty' => $tp->qtySold,
                ];
            }
        }
        usort($merged, fn ($a, $b) => $b['qtySold'] <=> $a['qtySold']);
        $topProducts = array_slice(array_values($merged), 0, 10);

        // Combined recent activity feed — newest first across all marketplaces.
        $recent = [];
        foreach ($providers as $provider) {
            $card = $cards->get($provider->integrationCard()?->id ?? '');
            if ($card === null) {
                continue;
            }
            foreach ($provider->recentOrders(5) as $ro) {
                $recent[] = [
                    'order' => $ro,
                    'card' => $card,
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
