<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;

class ShopeeVoucherController extends Controller
{
    private const STATUSES = ['all', 'upcoming', 'ongoing', 'expired'];

    public function index(Request $request, ShopeeClient $client)
    {
        $status = (string) $request->input('status', 'all');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'all';
        }

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);

        $vouchers = null;
        $liveError = null;
        if ($setting && $auth['complete']) {
            $result = $this->voucherCall($client, $auth, 'GET', '/api/v2/voucher/get_voucher_list', [
                'status' => $status, 'page_no' => 1, 'page_size' => 100,
            ]);
            $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : 'no_body';
            if (($result['ok'] ?? false) && $apiError === '') {
                $vouchers = collect($result['body']['response']['voucher_list'] ?? [])
                    ->map(function (array $v) {
                        $start = (int) ($v['start_time'] ?? 0);
                        $end = (int) ($v['end_time'] ?? 0);
                        $v['state'] = $start > now()->timestamp ? 'upcoming'
                            : ($end < now()->timestamp ? 'expired' : 'ongoing');

                        return $v;
                    });
            } else {
                $liveError = is_array($result['body'] ?? null)
                    ? (string) ($result['body']['message'] ?? ($result['body']['error'] ?? 'No response.'))
                    : 'No response.';
            }
        } else {
            $liveError = 'Missing Shopee settings.';
        }

        return view('ext-shopee::vouchers.index', [
            'vouchers' => $vouchers,
            'liveError' => $liveError,
            'status' => $status,
        ]);
    }

    public function store(Request $request, ShopeeClient $client)
    {
        $data = $request->validate([
            'voucher_name' => 'required|string|max:20',
            'voucher_code' => 'required|string|regex:/^[A-Za-z0-9]{5}$/',
            'discount_amount' => 'nullable|numeric|min:1|required_without:percentage|prohibits:percentage',
            'percentage' => 'nullable|integer|min:1|max:99|required_without:discount_amount',
            'max_price' => 'nullable|numeric|min:1|required_with:percentage',
            'min_basket_price' => 'nullable|numeric|min:0',
            'usage_quantity' => 'required|integer|min:1',
            'starts_at' => 'required|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
        ], [
            'voucher_code.regex' => 'The code is 5 letters or numbers, nothing else.',
            'discount_amount.prohibits' => 'Give an amount off or a percent off, not both.',
            'starts_at.after' => 'The start has to be in the future.',
        ]);

        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return redirect()->route('ext.shopee.vouchers.index')->with('error', 'Missing Shopee settings.');
        }

        $fixed = ($data['discount_amount'] ?? null) !== null;
        $body = [
            'voucher_name' => $data['voucher_name'],
            'voucher_code' => strtoupper($data['voucher_code']),
            'start_time' => strtotime($data['starts_at']),
            'end_time' => strtotime($data['ends_at']),
            'voucher_type' => 1,
            'reward_type' => $fixed ? 1 : 2,
            'usage_quantity' => (int) $data['usage_quantity'],
            'min_basket_price' => (float) ($data['min_basket_price'] ?? 0),
            'display_channel_list' => [1],
        ];
        if ($fixed) {
            $body['discount_amount'] = (float) $data['discount_amount'];
        } else {
            $body['percentage'] = (int) $data['percentage'];
            $body['max_price'] = (float) $data['max_price'];
        }

        $result = $this->voucherCall($client, $auth, 'POST', '/api/v2/voucher/add_voucher', $body);
        $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : 'no_body';
        if (!($result['ok'] ?? false) || $apiError !== '' || empty($result['body']['response']['voucher_id'])) {
            return redirect()->route('ext.shopee.vouchers.index')
                ->with('error', 'Voucher creation failed: ' . $this->message($result))
                ->withInput();
        }

        return redirect()->route('ext.shopee.vouchers.index')
            ->with('status', 'Voucher ' . strtoupper($data['voucher_code']) . ' created. Buyers see it once it starts.');
    }

    public function end(Request $request, int $voucherId, ShopeeClient $client)
    {
        return $this->passThrough($client, '/api/v2/voucher/end_voucher', $voucherId,
            'Voucher ended. Buyers can no longer claim or use it.', 'End failed');
    }

    public function destroy(Request $request, int $voucherId, ShopeeClient $client)
    {
        return $this->passThrough($client, '/api/v2/voucher/delete_voucher', $voucherId,
            'Voucher deleted before it started. Buyers never saw it.', 'Delete failed');
    }

    private function passThrough(ShopeeClient $client, string $path, int $voucherId, string $okWords, string $failWord)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            return redirect()->route('ext.shopee.vouchers.index')->with('error', 'Missing Shopee settings.');
        }

        $result = $this->voucherCall($client, $auth, 'POST', $path, ['voucher_id' => $voucherId]);
        $apiError = is_array($result['body'] ?? null) ? (string) ($result['body']['error'] ?? '') : 'no_body';
        if (!($result['ok'] ?? false) || $apiError !== '') {
            return redirect()->route('ext.shopee.vouchers.index')
                ->with('error', $failWord . ': ' . $this->message($result));
        }

        return redirect()->route('ext.shopee.vouchers.index')->with('status', $okWords);
    }

    private function voucherCall(ShopeeClient $client, array $auth, string $method, string $path, array $payload): array
    {
        try {
            $result = $method === 'GET'
                ? $client->shopGet(
                    $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'], $path, $payload)
                : $client->shopPost(
                    $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'], $path, [], $payload);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['error' => 'network', 'message' => \App\Support\TransportError::plain($e, 'Shopee')]];
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.vouchers', 'method' => $method,
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $payload,
            'response_status' => $result['status'] ?? null,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        return $result;
    }

    private function message(array $result): string
    {
        return is_array($result['body'] ?? null)
            ? (string) ($result['body']['message'] ?? ($result['body']['error'] ?? 'no response'))
            : 'no response';
    }
}
