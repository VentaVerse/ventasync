<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Http\Request;

class TikTokCouponController extends Controller
{
    private const API = '/promotion/202406/coupons';

    public const STATUS_MAP = [
        'NOT_START' => ['Upcoming', 'info'],
        'ONGOING' => ['Ongoing', 'success'],
        'EXPIRED' => ['Expired', 'neutral'],
        'DEACTIVATED' => ['Ended', 'neutral'],
        'DRAFT' => ['Draft', 'warning'],
    ];

    public function index(Request $request, TikTokClient $client)
    {
        $status = strtoupper((string) $request->input('status', 'all'));
        if ($status !== 'ALL' && !isset(self::STATUS_MAP[$status])) {
            $status = 'ALL';
        }

        $c = $this->creds();
        $coupons = null;
        $liveError = null;
        if (!$c) {
            $liveError = 'Missing TikTok settings.';
        } else {
            $body = $status === 'ALL' ? [] : ['status' => [$status]];
            $result = $this->call($client, $c, 'POST', self::API . '/search', $body, ['page_size' => 100]);
            if ($this->callOk($result)) {
                $coupons = collect($result['body']['data']['coupons'] ?? []);
            } else {
                $liveError = $this->callMessage($result);
            }
        }

        return view('ext-tiktok::coupons.index', [
            'coupons' => $coupons,
            'liveError' => $liveError,
            'status' => strtolower($status),
        ]);
    }

    public function store(Request $request, TikTokClient $client)
    {
        $data = $request->validate([
            'title' => 'required|string|max:50',
            'money_off' => 'nullable|numeric|min:1|required_without:percentage_off|prohibits:percentage_off',
            'percentage_off' => 'nullable|integer|min:1|max:99|required_without:money_off',
            'max_discount' => 'nullable|numeric|min:1|required_with:percentage_off',
            'min_spend' => 'nullable|numeric|min:0',
            'total_claim_count' => 'required|integer|min:1',
            'starts_at' => 'required|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
        ], [
            'money_off.prohibits' => 'Give an amount off or a percent off, not both.',
            'starts_at.after' => 'The start has to be in the future.',
        ]);

        $c = $this->creds();
        if (!$c) {
            return redirect()->route('ext.tiktok.coupons.index')->with('error', 'Missing TikTok settings.');
        }

        $fixed = ($data['money_off'] ?? null) !== null;
        $minSpend = (float) ($data['min_spend'] ?? 0);
        $start = strtotime($data['starts_at']);
        $end = strtotime($data['ends_at']);

        $body = [
            'title' => $data['title'],
            'display_type' => 'REGULAR',
            'target_buyer_segment' => 'ALL',
            'product_scope' => ['type' => 'ALL'],
            'discount' => $fixed
                ? ['type' => 'MONEY_OFF', 'money_off' => number_format((float) $data['money_off'], 2, '.', '')]
                : ['type' => 'PERCENTAGE_OFF', 'percentage_off' => (int) $data['percentage_off'],
                   'max_discount' => number_format((float) $data['max_discount'], 2, '.', '')],
            'threshold' => $minSpend > 0
                ? ['type' => 'MIN_SPEND', 'min_spend' => number_format($minSpend, 2, '.', '')]
                : ['type' => 'NO_THRESHOLD'],
            'usage_limits' => ['total_claim_count' => (int) $data['total_claim_count'], 'per_buyer_claim_count' => 1],
            'claim_duration' => ['start_time' => $start, 'end_time' => $end],
            'redemption_duration' => ['type' => 'FIXED', 'start_time' => $start, 'end_time' => $end],
        ];

        $result = $this->call($client, $c, 'POST', self::API, $body);
        $couponId = (string) ($result['body']['data']['coupon_id'] ?? ($result['body']['data']['id'] ?? ''));
        if (!$this->callOk($result) || $couponId === '') {
            return redirect()->route('ext.tiktok.coupons.index')
                ->with('error', 'Coupon creation failed: ' . $this->callMessage($result))
                ->withInput();
        }

        return redirect()->route('ext.tiktok.coupons.index')
            ->with('status', 'Coupon "' . $data['title'] . '" created. Buyers see it once it starts.');
    }

    public function deactivate(Request $request, string $couponId, TikTokClient $client)
    {
        $c = $this->creds();
        if (!$c) {
            return redirect()->route('ext.tiktok.coupons.index')->with('error', 'Missing TikTok settings.');
        }

        $result = $this->call($client, $c, 'POST', self::API . '/' . $couponId . '/deactivate', []);
        if (!$this->callOk($result)) {
            return redirect()->route('ext.tiktok.coupons.index')
                ->with('error', 'Deactivate failed: ' . $this->callMessage($result));
        }

        return redirect()->route('ext.tiktok.coupons.index')
            ->with('status', 'Coupon deactivated. Buyers can no longer claim or use it, and it cannot restart.');
    }

    private function creds(): ?array
    {
        $s = TikTokSetting::defaultStore();
        if (!$s) {
            return null;
        }
        $d = $s->decrypted();
        $sandbox = $s->mode === 'sandbox';

        return [
            'app_key' => $sandbox ? ($d->sandbox_app_key ?? '') : ($d->app_key ?? ''),
            'app_secret' => $sandbox ? ($d->sandbox_app_secret ?? '') : ($d->app_secret ?? ''),
            'token' => $sandbox ? ($d->sandbox_access_token ?? '') : ($d->access_token ?? ''),
            'shop_cipher' => $sandbox ? ($s->sandbox_shop_cipher ?? '') : ($s->shop_cipher ?? ''),
        ];
    }

    private function call(TikTokClient $client, array $c, string $method, string $path, array $body, array $query = []): array
    {
        try {
            $result = $client->post($c['app_key'], $c['app_secret'], $c['token'], $path, $query, $body, $c['shop_cipher']);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }

        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.coupons', 'method' => $method,
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $body + ($query ? ['_query' => $query] : []),
            'response_status' => $result['status'] ?? 0,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? [], 'user_id' => auth()->id(),
        ]);

        return $result;
    }

    private function callOk(array $result): bool
    {
        return ($result['ok'] ?? false) && (int) (($result['body']['code'] ?? -1)) === 0;
    }

    private function callMessage(array $result): string
    {
        return \App\Support\MarketplaceAnswer::errorText('TikTok Shop', $result);
    }
}
