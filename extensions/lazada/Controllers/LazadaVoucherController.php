<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;

class LazadaVoucherController extends Controller
{
    private const LIST_PATH = '/promotion/vouchers/get';
    private const CREATE_PATH = '/promotion/voucher/create';
    private const DEACTIVATE_PATH = '/promotion/voucher/deactivate';

    public const STATUS_MAP = [
        'not_start' => ['Upcoming', 'info'],
        'upcoming' => ['Upcoming', 'info'],
        'ongoing' => ['Ongoing', 'success'],
        'expired' => ['Expired', 'neutral'],
        'deactivated' => ['Ended', 'neutral'],
        'ended' => ['Ended', 'neutral'],
    ];

    public function index(Request $request, LazadaClient $client)
    {
        $status = strtolower((string) $request->input('status', 'all'));
        if ($status !== 'all' && !isset(self::STATUS_MAP[$status])) {
            $status = 'all';
        }

        [$setting, $creds] = $this->creds();
        $vouchers = null;
        $liveError = null;
        if (!$setting || !$creds['complete']) {
            $liveError = 'Missing Lazada settings.';
        } else {
            $query = ['voucher_type' => 'COLLECTIBLE_VOUCHER', 'cur_page' => '1', 'page_size' => '100'];
            if ($status !== 'all') {
                $query['status'] = $status;
            }
            $result = $this->call($client, $setting, $creds, 'GET', self::LIST_PATH, $query);
            if ($this->callOk($result)) {
                $vouchers = collect($result['body']['data']['voucher_list'] ?? ($result['body']['data']['vouchers'] ?? []))
                    ->map(function (array $v) {
                        $v['state'] = strtolower((string) ($v['status'] ?? ''));

                        return $v;
                    });
            } else {
                $liveError = $this->callMessage($result);
            }
        }

        return view('ext-lazada::vouchers.index', [
            'vouchers' => $vouchers,
            'liveError' => $liveError,
            'status' => $status,
        ]);
    }

    public function store(Request $request, LazadaClient $client)
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'money_off' => 'nullable|numeric|min:1|required_without:percentage_off|prohibits:percentage_off',
            'percentage_off' => 'nullable|integer|min:1|max:99|required_without:money_off',
            'max_discount' => 'nullable|numeric|min:1|required_with:percentage_off',
            'min_spend' => 'nullable|numeric|min:0',
            'issued' => 'required|integer|min:1',
            'starts_at' => 'required|date|after:now',
            'ends_at' => 'required|date|after:starts_at',
        ], [
            'money_off.prohibits' => 'Give an amount off or a percent off, not both.',
            'starts_at.after' => 'The start has to be in the future.',
        ]);

        [$setting, $creds] = $this->creds();
        if (!$setting || !$creds['complete']) {
            return redirect()->route('ext.lazada.vouchers.index')->with('error', 'Missing Lazada settings.');
        }

        $result = $this->call($client, $setting, $creds, 'POST', self::CREATE_PATH, $this->createParams($data));
        $voucherId = (string) ($result['body']['data']['voucher_id'] ?? ($result['body']['data']['id'] ?? ''));
        if (!$this->callOk($result) || $voucherId === '') {
            return redirect()->route('ext.lazada.vouchers.index')
                ->with('error', 'Voucher creation failed: ' . $this->callMessage($result))
                ->withInput();
        }

        return redirect()->route('ext.lazada.vouchers.index')
            ->with('status', 'Voucher "' . $data['name'] . '" created. Buyers see it once it starts.');
    }

    public function deactivate(Request $request, string $voucherId, LazadaClient $client)
    {
        [$setting, $creds] = $this->creds();
        if (!$setting || !$creds['complete']) {
            return redirect()->route('ext.lazada.vouchers.index')->with('error', 'Missing Lazada settings.');
        }

        $result = $this->call($client, $setting, $creds, 'POST', self::DEACTIVATE_PATH, [
            'voucher_id' => $voucherId, 'voucher_type' => 'COLLECTIBLE_VOUCHER',
        ]);
        if (!$this->callOk($result)) {
            return redirect()->route('ext.lazada.vouchers.index')
                ->with('error', 'Deactivate failed: ' . $this->callMessage($result));
        }

        return redirect()->route('ext.lazada.vouchers.index')
            ->with('status', 'Voucher deactivated. Buyers can no longer collect or use it, and it cannot restart.');
    }

    private function createParams(array $data): array
    {
        $fixed = ($data['money_off'] ?? null) !== null;
        $minSpend = (float) ($data['min_spend'] ?? 0);
        $startMs = strtotime($data['starts_at']) * 1000;
        $endMs = strtotime($data['ends_at']) * 1000;

        $params = [
            'voucher_type' => 'COLLECTIBLE_VOUCHER',
            'name' => $data['name'],
            'apply' => 'ENTIRE_SHOP',
            'display_area' => 'SHOP',
            'period_start_time' => (string) $startMs,
            'period_end_time' => (string) $endMs,
            'collect_start' => (string) $startMs,
            'issued' => (string) (int) $data['issued'],
            'limit' => '1',
            'criteria_over_money' => number_format($minSpend, 2, '.', ''),
            'discount_type' => $fixed ? 'MONEY_VALUE_OFF' : 'PERCENTAGE_OFF',
        ];
        if ($fixed) {
            $params['discount_value'] = number_format((float) $data['money_off'], 2, '.', '');
        } else {
            $params['discount_value'] = (string) (int) $data['percentage_off'];
            $params['max_discount_offering_money_value'] = number_format((float) $data['max_discount'], 2, '.', '');
        }

        return $params;
    }

    private function creds(): array
    {
        $setting = LazadaSetting::defaultStore();
        $decrypted = $setting?->decrypted();
        $creds = LazadaSetting::activeCredentials($decrypted);
        if ($setting && !$setting->region) {
            $creds['complete'] = false;
        }

        return [$setting, $creds];
    }

    private function call(LazadaClient $client, object $setting, array $creds, string $method, string $apiPath, array $extra): array
    {
        $params = array_merge([
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
        ], $extra);
        $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
        $mode = (string) ($creds['mode'] ?? 'live');

        try {
            $result = $method === 'GET'
                ? $client->get((string) $setting->region, $apiPath, $params, $mode)
                : $client->post((string) $setting->region, $apiPath, $params, $mode);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'Lazada')]];
        }

        LazadaApiLog::safeCreate([
            'pack' => 'lazada.vouchers', 'method' => $method,
            'api_path' => $apiPath, 'auth_required' => true,
            'request_params' => $extra,
            'response_status' => $result['status'] ?? null,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        return $result;
    }

    private function callOk(array $result): bool
    {
        $body = $result['body'] ?? null;
        $code = is_array($body) ? (string) ($body['code'] ?? '') : 'no_body';

        return ($result['ok'] ?? false) && ($code === '' || $code === '0');
    }

    private function callMessage(array $result): string
    {
        return \App\Support\MarketplaceAnswer::errorText('Lazada', $result);
    }
}
