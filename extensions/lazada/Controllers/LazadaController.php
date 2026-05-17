<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;

use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaController extends Controller
{
    /**
     * Lazada Open Platform image migrate endpoints require the "payload" parameter
     * to be an XML string (NOT JSON). This normalizes common JSON shapes into
     * the exact XML format shown in Lazada API Explorer.
     */
    private function normalizeImageMigratePayload(string $apiPath, $payload): ?string
    {
        if (!in_array($apiPath, ['/images/migrate', '/image/migrate'], true)) {
            return null;
        }

        // If already XML string, keep it.
        if (is_string($payload)) {
            $trim = trim($payload);
            if ($trim === '') return null;
            if (str_starts_with($trim, '<?xml') || str_starts_with($trim, '<Request')) {
                return $trim;
            }

            // If user pasted JSON, decode and rebuild.
            if (str_starts_with($trim, '{') || str_starts_with($trim, '[')) {
                try {
                    $payload = json_decode($trim, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    return $trim;
                }
            } else {
                return $trim;
            }
        }

        if (!is_array($payload)) return null;
        $req = $payload['Request'] ?? $payload['request'] ?? null;
        if (!is_array($req)) return null;

        $urls = [];
        $extract = function ($node) use (&$urls) {
            if (!is_array($node)) return;
            $u = $node['Url'] ?? $node['url'] ?? null;
            if (is_string($u)) {
                $u = trim($u);
                if ($u !== '') $urls[] = $u;
                return;
            }
            if (is_array($u)) {
                foreach ($u as $it) {
                    if (is_string($it)) {
                        $it = trim($it);
                        if ($it !== '') $urls[] = $it;
                    }
                }
            }
        };

        $images = $req['Images'] ?? $req['images'] ?? null;
        $image = $req['Image'] ?? $req['image'] ?? null;
        if (is_array($images)) $extract($images);
        if (is_array($image)) $extract($image);

        $urls = array_values(array_unique(array_filter(array_map(fn($u) => trim((string)$u), $urls), fn($u) => $u !== '')));
        if (empty($urls)) return null;

        $header = '<?xml version="1.0" encoding="UTF-8" ?>';
        if ($apiPath === '/image/migrate') {
            return $header . '<Request><Image><Url>' . htmlspecialchars($urls[0], ENT_XML1) . '</Url></Image></Request>';
        }
        return $header . '<Request><Images>'
            . implode('', array_map(fn($u) => '<Url>' . htmlspecialchars($u, ENT_XML1) . '</Url>', $urls))
            . '</Images></Request>';
    }

    public function index()
    {
        $setting = LazadaSetting::query()->first();

        $logs = LazadaApiLog::query()
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        // Lightweight list for dropdowns (kept small for production safety)
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $products = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->orderByDesc('p.product_id')
            ->limit(200)
            ->get(['p.product_id', 'p.sku', 'p.model', 'pd.name']);

        foreach ($products as $row) {
            $row->name = html_entity_decode((string)($row->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $row->sku = html_entity_decode((string)($row->sku ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $row->model = html_entity_decode((string)($row->model ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Order status mapping data
        $orderStatusMap = \Extensions\lazada\Models\LazadaOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'lazada_status')->all();

        $erpOrderStatuses = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->orderBy('order_status_id')
            ->get(['order_status_id', 'name']);

        $lazadaStatuses = [
            'unpaid'          => 'Unpaid',
            'pending'         => 'Pending',
            'repacked'        => 'Repacked',
            'packed'          => 'Packed',
            'ready_to_ship'   => 'Ready to Ship',
            'shipped'         => 'Shipped',
            'delivered'       => 'Delivered',
            'confirmed'       => 'Confirmed (Buyer received)',
            'failed_delivery' => 'Failed Delivery',
            'lost_by_3pl'     => 'Lost by 3PL',
            'damaged_by_3pl'  => 'Damaged by 3PL',
            'canceled'        => 'Canceled',
            'cancelled'       => 'Cancelled',
        ];

        $reverseStatuses = [
            'returned'            => 'Returned',
            'return_initiated'    => 'Return Initiated',
            'in_progress'         => 'In Progress',
            'processing'          => 'Processing',
            'approved'            => 'Approved',
            'shipped_back'        => 'Shipped Back',
            'shipped_back_success' => 'Shipped Back (Received)',
            'received'            => 'Received',
            'dispute_in_progress' => 'Dispute in Progress',
            'refund_paid'         => 'Refund Issued',
            'closed'              => 'Closed',
            'rejected'            => 'Rejected',
            'cancelled'           => 'Cancelled',
        ];

        $reverseStatusMap = \Extensions\lazada\Models\LazadaOrderStatusMap::where('context', 'return')
            ->whereIn('lazada_status', array_keys($reverseStatuses))
            ->pluck('order_status_id', 'lazada_status')
            ->all();

        return view('ext-lazada::index', [
            'setting' => $setting?->decrypted(),
            'defaultRedirect' => $this->computedRedirectUri(),
            'result' => session('lazada_result'),
            'products' => $products,
            'logs' => $logs,
            'orderStatusMap' => $orderStatusMap,
            'erpOrderStatuses' => $erpOrderStatuses,
            'lazadaStatuses' => $lazadaStatuses,
            'reverseStatuses' => $reverseStatuses,
            'reverseStatusMap' => $reverseStatusMap,
        ]);
    }

    /**
     * Save the Lazada → ERP order status mapping.
     */
    public function saveOrderStatusMap(Request $request)
    {
        $map = $request->input('map', []);

        if (!is_array($map)) {
            return back()->with('status', 'Invalid mapping data.');
        }

        foreach ($map as $lazadaStatus => $orderStatusId) {
            $lazadaStatus = strtolower(trim((string) $lazadaStatus));
            $orderStatusId = (int) $orderStatusId;

            if ($lazadaStatus === '' || $orderStatusId <= 0) {
                continue;
            }

            \Extensions\lazada\Models\LazadaOrderStatusMap::updateOrCreate(
                ['lazada_status' => $lazadaStatus, 'context' => 'order'],
                ['order_status_id' => $orderStatusId]
            );
        }

        return back()->with('status', 'Order status mapping saved.');
    }

    /**
     * Save the "Sync Last N Days" setting for Lazada order sync.
     */
    public function saveSyncDays(Request $request)
    {
        $request->validate([
            'sync_last_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $setting = LazadaSetting::query()->first();
        if (!$setting) {
            return back()->with('error', 'Lazada settings not found.');
        }

        $setting->update([
            'sync_last_days' => $request->input('sync_last_days') ?: null,
        ]);

        $days = $request->input('sync_last_days');
        $msg = $days
            ? "Order sync window set to last {$days} days."
            : 'Order sync window cleared (defaults to 14 days).';

        return back()->with('status', $msg);
    }

    /**
     * Save the "Sync Last N Days" setting for Lazada returns sync (independent from orders).
     */
    public function saveSyncDaysReturns(Request $request)
    {
        $request->validate([
            'sync_last_days_returns' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $setting = LazadaSetting::query()->first();
        if (!$setting) {
            return back()->with('error', 'Lazada settings not found.');
        }

        $setting->update([
            'sync_last_days_returns' => $request->input('sync_last_days_returns') ?: null,
        ]);

        $days = $request->input('sync_last_days_returns');
        $msg = $days
            ? "Returns sync window set to last {$days} days."
            : 'Returns sync window cleared (defaults to 14 days).';

        return back()->with('status', $msg);
    }

    /**
     * Save the Lazada → ERP reverse order status mapping.
     */
    public function saveReverseStatusMap(Request $request)
    {
        $map = $request->input('map', []);

        if (!is_array($map)) {
            return back()->with('status', 'Invalid mapping data.');
        }

        foreach ($map as $lazadaStatus => $orderStatusId) {
            $lazadaStatus = strtolower(trim((string) $lazadaStatus));
            $orderStatusId = (int) $orderStatusId;

            if ($lazadaStatus === '') {
                continue;
            }

            if ($orderStatusId <= 0) {
                \Extensions\lazada\Models\LazadaOrderStatusMap::where('lazada_status', $lazadaStatus)
                    ->where('context', 'return')
                    ->delete();
                continue;
            }

            \Extensions\lazada\Models\LazadaOrderStatusMap::updateOrCreate(
                ['lazada_status' => $lazadaStatus, 'context' => 'return'],
                ['order_status_id' => $orderStatusId]
            );
        }

        return back()->with('status', 'Reverse order status mapping saved.');
    }

    public function toggleLogging(Request $request)
    {
        $setting = LazadaSetting::query()->first();
        if (!$setting) {
            return back()->with('error', 'Lazada settings not found.');
        }

        $setting->update([
            'api_logging' => (bool) $request->input('api_logging'),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'api_logging' => $setting->api_logging]);
        }

        $state = $setting->api_logging ? 'enabled' : 'disabled';
        return redirect()->route('ext.lazada.index', ['tab' => 'logs'])->with('status', "Lazada API logging {$state}.");
    }

    public function clearApiLogs()
    {
        $count = LazadaApiLog::count();
        LazadaApiLog::truncate();

        return redirect()->route('ext.lazada.index', ['tab' => 'logs'])->with('status', "Deleted {$count} Lazada API log entries.");
    }

    /**
     * Phase 1: API Explorer runner for testing arbitrary endpoints.
     * Supports GET/POST and optional auth (access_token).
     */
    public function explorerRun(Request $request, LazadaClient $client)
    {
        $data = $request->validate([
            'method' => 'required|in:GET,POST',
            'api_path' => 'required|string|max:255',
            'auth_required' => 'nullable|boolean',
            'params_json' => 'nullable|string|max:20000',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, and App Secret are required.'],
            ]);
        }

        $authRequired = (bool)($data['auth_required'] ?? false);
        if ($authRequired && !$setting->access_token) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing Access Token',
                'data' => ['message' => 'Access Token is required for auth-required calls. Please authorize and refresh token.'],
            ]);
        }

        $apiPath = (string)$data['api_path'];
        if (!str_starts_with($apiPath, '/')) {
            $apiPath = '/' . $apiPath;
        }

        $customParams = [];
        $jsonRaw = trim((string)($data['params_json'] ?? ''));
        if ($jsonRaw !== '') {
            try {
                $decoded = json_decode($jsonRaw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $customParams = $decoded;
                }
            } catch (\Throwable $e) {
                return redirect()->route('ext.lazada.index')->withInput()->with('lazada_result', [
                    'ok' => false,
                    'title' => 'Invalid JSON',
                    'data' => ['message' => $e->getMessage()],
                ]);
            }
        }

        // Base params required by Lazada signing.
        $timestamp = (string)round(microtime(true) * 1000);
        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
        ];

        if ($authRequired) {
            $params['access_token'] = (string)$setting->access_token;
        }

        // Merge custom params (but never allow overriding of required base/sign fields)

        // Special-case: Lazada GetBrandByPages uses startRow + pageSize (NOT page_no/page_size).
        // Some UI forms may still send page_no/page_size, so normalize to what Lazada expects.
        if ($apiPath === '/category/brands/query') {
            $pn = $customParams['page_no'] ?? null;
            $ps = $customParams['page_size'] ?? null;
            if (($customParams['startRow'] ?? null) === null && $pn !== null) {
                $pnInt = max(1, (int)$pn);
                $psInt = $ps !== null ? max(1, (int)$ps) : 50;
                $customParams['startRow'] = (string)(($pnInt - 1) * $psInt);
                $customParams['pageSize'] = (string)$psInt;
                unset($customParams['page_no'], $customParams['page_size']);
            }
        }

        foreach ($customParams as $k => $v) {
            if (!is_string($k) || $k === '' || in_array($k, ['sign', 'app_key', 'sign_method', 'timestamp'], true)) {
                continue;
            }

            // Critical: /images/migrate and /image/migrate REQUIRE XML in payload.
            if ($k === 'payload') {
                $maybeXml = $this->normalizeImageMigratePayload($apiPath, $v);
                if (is_string($maybeXml) && $maybeXml !== '') {
                    $params[$k] = $maybeXml;
                    continue;
                }
            }

            $params[$k] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Lazada ImageResponseGet:
        // Lazada documentation says GET + auth required + batch_id.
        // In practice, some gateways are strict about parameter placement/encoding.
        // We will validate inputs, then run a deterministic multi-attempt strategy
        // and surface a single consolidated result + all attempts (for transparency).
        if ($apiPath === '/image/response/get') {
            $batchId = isset($params['batch_id']) ? trim((string)$params['batch_id']) : '';
            if ($batchId === '') {
                return redirect()->route('ext.lazada.index')->withInput()->with('lazada_result', [
                    'ok' => false,
                    'title' => 'Missing batch_id',
                    'data' => ['message' => 'batch_id is required for /image/response/get. Paste the batch_id returned by /images/migrate.'],
                ]);
            }

            // Safety: ensure access_token is present (docs say authorization required).
            // If user forgot to enable Auth checkbox, enforce it for this endpoint.
            if (!isset($params['access_token']) || trim((string)$params['access_token']) === '') {
                $params['access_token'] = (string)$setting->access_token;
            }

            // Some gateways reject unknown params; only send batch_id by default.
            $params['batch_id'] = $batchId;
        }


        // Normalize pagination params for specific Lazada endpoints
        if ($apiPath === '/category/brands/query') {
            // Lazada requires startRow + pageSize (not page_no/page_size)
            $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
            $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;

            if ($pageNo !== null && $pageSize !== null) {
                $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                $params['pageSize'] = max(1, $pageSize);
                unset($params['page_no'], $params['page_size']);
            } else {
                // if client already provided correct keys, ensure casing matches Lazada
                if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                    $params['pageSize'] = (int)$params['page_size'];
                    unset($params['page_size']);
                }
            }
        }

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);

        $method = (string)$data['method'];

        // Special robust runner for /image/response/get to stop the "back and forth" loop.
        // We will:
        // - always try the doc method (GET)
        // - if unsupported, try POST once
        // - if E005, try a strict "payload" encoding variant
        // - if E208, poll a few times (the migrate job may not be ready yet)
        // - never mask the real root error; return consolidated diagnostics
        if ($apiPath === '/image/response/get') {
            $attempts = [];

            $extractCode = function ($body): ?string {
                if (is_array($body) && array_key_exists('code', $body)) {
                    return (string)$body['code'];
                }
                if (is_string($body)) {
                    $maybe = json_decode($body, true);
                    if (is_array($maybe) && array_key_exists('code', $maybe)) {
                        return (string)$maybe['code'];
                    }
                }
                // Some errors use string codes like "UnsupportedHTTPMethod" (ISV)
                if (is_array($body) && array_key_exists('code', $body) && is_string($body['code'])) {
                    return (string)$body['code'];
                }
                return null;
            };

            $runAttempt = function (string $m, array $p, string $label) use ($client, $setting, $apiPath, $extractCode) {
                $p['sign'] = $client->sign($apiPath, $p, (string)$setting->app_secret);
                $res = $m === 'POST'
                    ? $client->post((string)$setting->region, $apiPath, $p)
                    : $client->get((string)$setting->region, $apiPath, $p);
                $code = $extractCode($res['body'] ?? null);
                return [$res, $code, $p, $label, $m];
            };

            $base = $params;
            // Ensure we don't accidentally carry a stale payload from UI.
            unset($base['payload']);

            // Attempt A: POST as form (most compatible) + flat batch_id
            [$resA, $codeA, $paramsA, $labelA, $mA] = $runAttempt('POST', $base, 'POST flat batch_id');
            $attempts[] = ['label' => $labelA, 'method' => $mA, 'code' => $codeA, 'result' => $resA];

            $isSuccess = function (array $res, ?string $code): bool {
                if (!($res['ok'] ?? false)) return false;
                if ($code === null) return true;
                return $code === '0';
            };

            if ($isSuccess($resA, $codeA)) {
                $result = $resA;
                $params = $paramsA;
                $method = 'POST';
                $result['meta'] = ['attempts' => $attempts, 'selected' => $labelA];
            } else {
                // If E208 (empty response), poll a few times using the same format.
                if ($codeA === '208') {
                    $polled = $resA;
                    $pollCode = $codeA;
                    for ($i = 0; $i < 3; $i++) {
                        usleep(400000); // 400ms
                        [$tmpRes, $tmpCode, $tmpParams, $tmpLabel, $tmpMethod] = $runAttempt('POST', $base, 'POST flat batch_id (poll ' . ($i + 1) . ')');
                        $attempts[] = ['label' => $tmpLabel, 'method' => $tmpMethod, 'code' => $tmpCode, 'result' => $tmpRes];
                        $polled = $tmpRes;
                        $pollCode = $tmpCode;
                        if ($isSuccess($tmpRes, $tmpCode)) {
                            $result = $tmpRes;
                            $params = $tmpParams;
                            $method = 'POST';
                            $result['meta'] = ['attempts' => $attempts, 'selected' => $tmpLabel];
                            break;
                        }
                    }
                    if (!isset($result)) {
                        $result = $polled;
                        $params = $base;
                        $method = 'POST';
                        $result['meta'] = [
                            'attempts' => $attempts,
                            'selected' => $labelA,
                            'note' => 'E208 usually means the migrate job is not ready yet. Try again after a few seconds using the same batch_id.',
                        ];
                    }
                } else {
                    // Attempt B: Some gateways reject flat params but accept a JSON-string payload.
                    // Keep batch_id present (to satisfy gateways that strictly require it).
                    $b = $base;
                    $b['payload'] = json_encode(['batch_id' => (string)$base['batch_id']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    [$resB, $codeB, $paramsB, $labelB, $mB] = $runAttempt('POST', $b, 'POST batch_id + payload');
                    $attempts[] = ['label' => $labelB, 'method' => $mB, 'code' => $codeB, 'result' => $resB];

                    if ($isSuccess($resB, $codeB)) {
                        $result = $resB;
                        $params = $paramsB;
                        $method = 'POST';
                        $result['meta'] = ['attempts' => $attempts, 'selected' => $labelB];
                    } else {
                        // Attempt C: GET (docs) + flat batch_id.
                        [$resC, $codeC, $paramsC, $labelC, $mC] = $runAttempt('GET', $base, 'GET flat batch_id');
                        $attempts[] = ['label' => $labelC, 'method' => $mC, 'code' => $codeC, 'result' => $resC];

                        // Attempt D: include request_id mirror (only if some gateways require it)
                        $d = $base;
                        $d['request_id'] = (string)($base['batch_id'] ?? '');
                        [$resD, $codeD, $paramsD, $labelD, $mD] = $runAttempt('POST', $d, 'POST batch_id + request_id');
                        $attempts[] = ['label' => $labelD, 'method' => $mD, 'code' => $codeD, 'result' => $resD];

                        // Pick best candidate deterministically.
                        $candidates = [
                            ['label' => $labelA, 'res' => $resA, 'code' => $codeA, 'params' => $paramsA, 'method' => 'POST'],
                            ['label' => $labelB, 'res' => $resB, 'code' => $codeB, 'params' => $paramsB, 'method' => 'POST'],
                            ['label' => $labelC, 'res' => $resC, 'code' => $codeC, 'params' => $paramsC, 'method' => 'GET'],
                            ['label' => $labelD, 'res' => $resD, 'code' => $codeD, 'params' => $paramsD, 'method' => 'POST'],
                        ];

                        $score = function (?string $code, array $res): int {
                            if (($res['ok'] ?? false) && ($code === null || $code === '0')) return 100;
                            if ($code === null) return 50;
                            if ($code === '208') return 40;
                            if ($code === '5') return 10;
                            if (is_array($res['body'] ?? null) && (($res['body']['code'] ?? null) === 'UnsupportedHTTPMethod')) return 0;
                            return 20;
                        };

                        usort($candidates, function ($a, $b) use ($score) {
                            return $score($b['code'], $b['res']) <=> $score($a['code'], $a['res']);
                        });

                        $best = $candidates[0];
                        $result = $best['res'];
                        $params = $best['params'];
                        $method = $best['method'];
                        $result['meta'] = [
                            'attempts' => $attempts,
                            'selected' => $best['label'],
                            'hint' => 'The Explorer automatically tried the supported formats for this endpoint. Check meta.attempts in the latest API log for full details.',
                        ];
                    }
                }
            }
        } else {
            $result = $method === 'POST'
                ? $client->post((string)$setting->region, $apiPath, $params)
                : $client->get((string)$setting->region, $apiPath, $params);
        }

// Log request+response for debugging (must never block UI)
        LazadaApiLog::safeCreate([
            'pack' => 'lazada.api.explorer',
            'method' => $method,
            'api_path' => $apiPath,
            'auth_required' => $authRequired,
            'request_params' => $params,
            'response_status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('ext.lazada.index')->withInput()->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'API Explorer: ' . $method . ' ' . $apiPath,
            'data' => $result,
        ]);
    }

    /**
     * Phase 1: Preset Packs runner.
     * Runs a curated sequence of API calls in order and logs each call.
     */
    public function packsRun(Request $request, LazadaClient $client)
    {
        $data = $request->validate([
            'pack' => 'required|in:catalog,orders,shipping,reverse,finance,full',
            'params_json' => 'nullable|string|max:20000',
        ]);

        $pack = (string)$data['pack'];
        $params = [];
        $jsonRaw = trim((string)($data['params_json'] ?? ''));
        if ($jsonRaw !== '') {
            try {
                $decoded = json_decode($jsonRaw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            } catch (\Throwable $e) {
                return redirect()->route('ext.lazada.index')->withInput()->with('lazada_result', [
                    'ok' => false,
                    'title' => 'Invalid JSON (Preset Pack)',
                    'data' => ['message' => $e->getMessage()],
                ]);
            }
        }

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, and App Secret are required.'],
            ]);
        }

        $steps = $this->buildPackSteps($pack, $params);
        if (empty($steps)) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Preset Pack',
                'data' => ['message' => 'No steps were generated for this pack.'],
            ]);
        }

        $results = [];
        $allOk = true;

        foreach ($steps as $step) {
            $res = $this->runSignedApiCall(
                $client,
                (string)$setting->region,
                (string)$setting->app_key,
                (string)$setting->app_secret,
                (string)($setting->access_token ?? ''),
                (string)$step['method'],
                (string)$step['api_path'],
                (bool)$step['auth_required'],
                (array)($step['params'] ?? []),
                $pack
            );

            $results[] = [
                'title' => $step['title'] ?? ($step['method'] . ' ' . $step['api_path']),
                'method' => $step['method'],
                'api_path' => $step['api_path'],
                'auth_required' => (bool)$step['auth_required'],
                'result' => $res,
            ];

            if (!($res['ok'] ?? false)) {
                $allOk = false;
            }
        }

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => $allOk,
            'title' => 'Preset Pack: ' . strtoupper($pack),
            'data' => ['pack' => $pack, 'steps' => $results],
        ]);
    }

    private function buildPackSteps(string $pack, array $input): array
    {
        $primaryCategoryId = isset($input['primary_category_id']) ? trim((string)$input['primary_category_id']) : '';
        $sellerSku = isset($input['seller_sku']) ? trim((string)$input['seller_sku']) : '';
        $orderId = isset($input['order_id']) ? trim((string)$input['order_id']) : '';

        $startTime = isset($input['start_time']) ? trim((string)$input['start_time']) : '';
        $endTime = isset($input['end_time']) ? trim((string)$input['end_time']) : '';
        $updateAfter = isset($input['update_after']) ? trim((string)$input['update_after']) : '';

        $limit = isset($input['limit']) ? (int)$input['limit'] : 10;
        $offset = isset($input['offset']) ? (int)$input['offset'] : 0;

        $catalog = [
            [
                'title' => 'Category Tree',
                'method' => 'GET',
                'api_path' => '/category/tree/get',
                'auth_required' => false,
                'params' => [],
            ],
        ];
        if ($primaryCategoryId !== '') {
            $catalog[] = [
                'title' => 'Category Attributes',
                'method' => 'GET',
                'api_path' => '/category/attributes/get',
                'auth_required' => false,
                'params' => ['primary_category_id' => $primaryCategoryId],
            ];
        }
        $catalog[] = [
            'title' => 'Brands (page 1)',
            'method' => 'GET',
            'api_path' => '/category/brands/query',
            'auth_required' => false,
            'params' => ['startRow' => 0, 'pageSize' => 50],
        ];
        $catalog[] = [
            'title' => 'Products List',
            'method' => 'GET',
            'api_path' => '/products/get',
            'auth_required' => true,
            'params' => ['filter' => 'all', 'limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)],
        ];
        if ($sellerSku !== '') {
            $catalog[] = [
                'title' => 'QC Status',
                'method' => 'GET',
                'api_path' => '/product/qc/status/get',
                'auth_required' => true,
                'params' => ['seller_sku' => $sellerSku],
            ];
        }

        $orders = [
            [
                'title' => 'Orders (range)',
                'method' => 'GET',
                'api_path' => '/orders/get',
                'auth_required' => true,
                'params' => array_filter([
                    'update_after' => $updateAfter !== '' ? $updateAfter : null,
                    'sort_direction' => 'DESC',
                    'limit' => max(1, min(100, $limit)),
                    'offset' => max(0, $offset),
                ], fn($v) => $v !== null && $v !== ''),
            ],
        ];
        if ($orderId !== '') {
            $orders[] = [
                'title' => 'Order (single)',
                'method' => 'GET',
                'api_path' => '/order/get',
                'auth_required' => true,
                'params' => ['order_id' => $orderId],
            ];
            $orders[] = [
                'title' => 'Order Items',
                'method' => 'GET',
                'api_path' => '/order/items/get',
                'auth_required' => true,
                'params' => ['order_id' => $orderId],
            ];
        }

        $shipping = [
            [
                'title' => 'Shipment Providers',
                'method' => 'GET',
                'api_path' => '/shipment/providers/get',
                'auth_required' => true,
                'params' => [],
            ],
            [
                'title' => 'Order Shipment Providers (dropship)',
                'method' => 'GET',
                'api_path' => '/order/shipment/providers/get',
                'auth_required' => true,
                'params' => ['shipping_type' => 'dropship'],
            ],
        ];

        $reverse = [
            [
                'title' => 'Reverse Orders (range)',
                'method' => 'GET',
                'api_path' => '/reverse/getreverseordersforseller',
                'auth_required' => true,
                'params' => array_filter([
                    'update_after' => $updateAfter !== '' ? $updateAfter : null,
                    'limit' => max(1, min(100, $limit)),
                    'offset' => max(0, $offset),
                ], fn($v) => $v !== null && $v !== ''),
            ],
        ];

        $finance = [
            [
                'title' => 'Finance Transaction Details',
                'method' => 'GET',
                'api_path' => '/finance/transaction/details/get',
                'auth_required' => true,
                'params' => array_filter([
                    'start_time' => $startTime !== '' ? $startTime : null,
                    'end_time' => $endTime !== '' ? $endTime : null,
                    'limit' => max(1, min(100, $limit)),
                    'offset' => max(0, $offset),
                ], fn($v) => $v !== null && $v !== ''),
            ],
        ];

        return match ($pack) {
            'catalog' => $catalog,
            'orders' => $orders,
            'shipping' => $shipping,
            'reverse' => $reverse,
            'finance' => $finance,
            'full' => array_merge($catalog, $orders, $shipping, $reverse, $finance),
            default => [],
        };
    }

    private function runSignedApiCall(
        LazadaClient $client,
        string $region,
        string $appKey,
        string $appSecret,
        string $accessToken,
        string $method,
        string $apiPath,
        bool $authRequired,
        array $customParams,
        ?string $pack = null
    ): array {
        $apiPath = trim($apiPath);
        if ($apiPath === '') {
            return ['status' => 0, 'ok' => false, 'body' => ['message' => 'Missing api_path']];
        }
        if (!str_starts_with($apiPath, '/')) {
            $apiPath = '/' . $apiPath;
        }

        if ($authRequired && $accessToken === '') {
            return ['status' => 0, 'ok' => false, 'body' => ['message' => 'Missing access_token for auth-required call']];
        }

        $timestamp = (string)round(microtime(true) * 1000);
        $params = [
            'app_key' => $appKey,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
        ];
        if ($authRequired) {
            $params['access_token'] = $accessToken;
        }

        foreach ($customParams as $k => $v) {
            if (!is_string($k) || $k === '' || in_array($k, ['sign', 'app_key', 'sign_method', 'timestamp'], true)) {
                continue;
            }
            $params[$k] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }


        // Normalize pagination params for specific Lazada endpoints
        if ($apiPath === '/category/brands/query') {
            // Lazada requires startRow + pageSize (not page_no/page_size)
            $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
            $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;

            if ($pageNo !== null && $pageSize !== null) {
                $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                $params['pageSize'] = max(1, $pageSize);
                unset($params['page_no'], $params['page_size']);
            } else {
                // if client already provided correct keys, ensure casing matches Lazada
                if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                    $params['pageSize'] = (int)$params['page_size'];
                    unset($params['page_size']);
                }
            }
        }

        $params['sign'] = $client->sign($apiPath, $params, $appSecret);

        $method = strtoupper($method);
        $result = $method === 'POST'
            ? $client->post($region, $apiPath, $params)
            : $client->get($region, $apiPath, $params);

        LazadaApiLog::safeCreate([
            'pack' => $pack,
            'method' => $method,
            'api_path' => $apiPath,
            'auth_required' => $authRequired,
            'request_params' => $params,
            'response_status' => (int)($result['status'] ?? 0),
            'ok' => (bool)($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null,
            'user_id' => auth()->id(),
        ]);

        return $result;
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'env' => 'nullable|in:live,sandbox',
            'region' => 'nullable|in:ph,sg,my,id,th,vn',
            'app_key' => 'nullable|string|max:64',
            'app_secret' => 'nullable|string|max:255',
            'sandbox_app_key' => 'nullable|string|max:64',
            'sandbox_app_secret' => 'nullable|string|max:255',
        ]);

        $setting = LazadaSetting::query()->first() ?? new LazadaSetting();

        $setting->region = $data['region'] ?? null;

        $env = $data['env'] ?? 'live';
        $setting->mode = $env;

        if ($env === 'live') {
            $setting->app_key = $data['app_key'] ?? null;
            $setting->app_secret = isset($data['app_secret']) && $data['app_secret'] !== ''
                ? encrypt(trim($data['app_secret']))
                : null;
        } else {
            $setting->sandbox_app_key = $data['sandbox_app_key'] ?? null;
            $setting->sandbox_app_secret = isset($data['sandbox_app_secret']) && $data['sandbox_app_secret'] !== ''
                ? encrypt(trim($data['sandbox_app_secret']))
                : null;
        }

        $setting->save();

        return redirect()->route('ext.lazada.index')->with('status', ($env === 'sandbox' ? 'Sandbox' : 'Production') . ' settings saved.');
    }

    public function toggleMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:live,sandbox']);
        $setting = LazadaSetting::query()->first() ?? new LazadaSetting();
        $setting->mode = $data['mode'];
        $setting->save();

        return redirect()->route('ext.lazada.index')->with('status', 'Switched to ' . ($data['mode'] === 'sandbox' ? 'Sandbox' : 'Production') . ' mode.');
    }

    public function redirectToAuth(Request $request, LazadaClient $client)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();

        // ?sandbox=1 from "Authorize Sandbox" button forces sandbox mode.
        // Otherwise the mode toggle (`$setting->mode`) decides.
        $isSandbox = $request->has('sandbox')
            ? $request->boolean('sandbox')
            : (($setting->mode ?? 'live') === 'sandbox');

        $appKey = $isSandbox
            ? ($setting->sandbox_app_key ?? null)
            : ($setting->app_key ?? null);

        if (!$setting || !$appKey) {
            $modeLabel = $isSandbox ? 'Sandbox' : 'Production';
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "{$modeLabel} App Key is required to authorize."],
            ]);
        }

        // Stateless POC, but we still bind a state token to reduce accidental cross-account redirects.
        // The sandbox flag is stashed so callback() can route the auth code + token to the right columns.
        $state = Str::random(24);
        session([
            'lazada_oauth_state'   => $state,
            'lazada_oauth_sandbox' => $isSandbox,
        ]);

        $url = $client->authUrl((string)$appKey, $this->computedRedirectUri(), $state);

        return redirect()->away($url);
    }

    /**
     * The redirect URI we send to Lazada and display to the operator. Derived
     * from APP_URL with HTTPS forced — Lazada requires HTTPS for production
     * callbacks and a stable byte-for-byte match against the value registered
     * in the Lazada Open Platform console. Read-only in the UI prevents typos
     * silently breaking OAuth.
     */
    private function computedRedirectUri(): string
    {
        return preg_replace('/^http:/i', 'https:', route('lazada.callback'));
    }

    public function callback(Request $request, LazadaClient $client)
    {
        $code = (string)$request->query('code', '');
        $state = (string)$request->query('state', '');

        $saved = false;
        $saveError = null;

        // Optional state check (non-blocking in POC to avoid bricking prod behind proxies)
        $expected = (string)session('lazada_oauth_state');
        $stateOk = true;
        if ($expected !== '' && $state !== '' && !hash_equals($expected, $state)) {
            $stateOk = false;
        }

        // Read the sandbox flag set by redirectToAuth so we route the auth
        // code, token-exchange credentials, and persisted tokens to the right
        // column set (sandbox_* vs live).
        $isSandbox = (bool) session('lazada_oauth_sandbox', false);

        // Persist the auth code so it's visible in the UI even if token exchange fails.
        if ($code !== '') {
            try {
                $rawForCode = LazadaSetting::query()->first() ?? new LazadaSetting();
                if ($isSandbox) {
                    $rawForCode->sandbox_auth_code = encrypt($code);
                } else {
                    $rawForCode->auth_code = encrypt($code);
                }
                $rawForCode->save();
                $saved = true;
            } catch (\Throwable $e) {
                $saveError = $e->getMessage();
            }
        }

        // Also store in session so you can see it even if DB save failed.
        if ($code !== '') {
            session(['lazada_last_auth_code' => $code]);
        }

        // Auto token exchange to complete the POC when settings are present.
        $tokenResult = null;
        $setting = LazadaSetting::query()->first()?->decrypted();

        $appKey = $isSandbox ? ($setting->sandbox_app_key ?? null) : ($setting->app_key ?? null);
        $appSecret = $isSandbox ? ($setting->sandbox_app_secret ?? null) : ($setting->app_secret ?? null);

        if ($code !== '' && $stateOk && $setting && $setting->region && $appKey && $appSecret) {
            $tokenResult = $this->doTokenCreate($client, (string)$setting->region, (string)$appKey, (string)$appSecret, $code, $isSandbox ? 'sandbox' : 'live');

            if (($tokenResult['ok'] ?? false) && is_array($tokenResult['body'] ?? null)) {
                $access = $tokenResult['body']['access_token'] ?? null;
                $refresh = $tokenResult['body']['refresh_token'] ?? null;
                $expiresIn = $tokenResult['body']['expires_in'] ?? null;
                $refreshExpiresIn = $tokenResult['body']['refresh_expires_in'] ?? null;

                $raw = LazadaSetting::query()->first() ?? new LazadaSetting();

                if ($isSandbox) {
                    if ($access) {
                        $raw->sandbox_access_token = encrypt((string)$access);
                    }
                    if ($refresh) {
                        $raw->sandbox_refresh_token = encrypt((string)$refresh);
                    }
                    if (is_numeric($expiresIn)) {
                        $raw->sandbox_expires_at = now()->addSeconds((int)$expiresIn);
                    }
                    if (is_numeric($refreshExpiresIn)) {
                        $raw->sandbox_refresh_expires_at = now()->addSeconds((int)$refreshExpiresIn);
                    }
                } else {
                    if ($access) {
                        $raw->access_token = encrypt((string)$access);
                    }
                    if ($refresh) {
                        $raw->refresh_token = encrypt((string)$refresh);
                    }
                    if (is_numeric($expiresIn)) {
                        $raw->expires_at = now()->addSeconds((int)$expiresIn);
                    }
                    if (is_numeric($refreshExpiresIn)) {
                        $raw->refresh_expires_at = now()->addSeconds((int)$refreshExpiresIn);
                    }
                }

                $raw->account = $tokenResult['body']['account'] ?? $raw->account;
                $raw->country = $tokenResult['body']['country'] ?? $raw->country;

                try {
                    $raw->save();
                } catch (\Throwable $e) {
                    // ignore save issues here; we still show tokenResult for debugging
                }
            }
        }

        $message = null;

        if ($code === '') {
            $message = 'No `code` returned to the callback. This usually means Lazada never called the callback URL you expect, or your web server redirected the callback URL somewhere else.';
        } elseif (!$stateOk) {
            $message = 'OAuth state mismatch. Re-authorize from the Lazada page.';
        } elseif ($saveError) {
            $message = 'Auth code received but could not be saved to the database. Most common cause: migrations not run yet. Save error: ' . $saveError;
        } elseif ($saved) {
            $message = 'Auth code received and saved.';
        }

        return view('ext-lazada::callback', [
            'code' => $code !== '' ? $code : null,
            'state' => $state !== '' ? $state : null,
            'state_ok' => $stateOk,
            'saved' => $saved,
            'save_error' => $saveError,
            'token_result' => $tokenResult,
            'message' => $message,
            'query' => $request->query(),
        ]);
    }

    public function tokenCreate(Request $request, LazadaClient $client)
    {
        $request->validate([
            'code' => 'required|string',
            'sandbox' => 'nullable|in:0,1',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();

        // The form may carry an explicit `sandbox` flag (the sandbox tab posts
        // <input name="sandbox" value="1">). Otherwise fall back to the mode toggle.
        $isSandbox = $request->has('sandbox')
            ? $request->boolean('sandbox')
            : (($setting->mode ?? 'live') === 'sandbox');

        $appKey = $isSandbox ? ($setting->sandbox_app_key ?? null) : ($setting->app_key ?? null);
        $appSecret = $isSandbox ? ($setting->sandbox_app_secret ?? null) : ($setting->app_secret ?? null);

        if (!$setting || !$setting->region || !$appKey || !$appSecret) {
            $modeLabel = $isSandbox ? 'Sandbox' : 'Production';
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Region, {$modeLabel} App Key, and {$modeLabel} App Secret are required."],
            ]);
        }

        $result = $this->doTokenCreate($client, (string)$setting->region, (string)$appKey, (string)$appSecret, (string)$request->input('code'), $isSandbox ? 'sandbox' : 'live');

        // Persist the resulting token to the right column set, mirroring callback().
        if (($result['ok'] ?? false) && is_array($result['body'] ?? null)) {
            $access = $result['body']['access_token'] ?? null;
            $refresh = $result['body']['refresh_token'] ?? null;
            $expiresIn = $result['body']['expires_in'] ?? null;
            $refreshExpiresIn = $result['body']['refresh_expires_in'] ?? null;

            $raw = LazadaSetting::query()->first() ?? new LazadaSetting();

            if ($isSandbox) {
                if ($access) $raw->sandbox_access_token = encrypt((string)$access);
                if ($refresh) $raw->sandbox_refresh_token = encrypt((string)$refresh);
                if (is_numeric($expiresIn)) $raw->sandbox_expires_at = now()->addSeconds((int)$expiresIn);
                if (is_numeric($refreshExpiresIn)) $raw->sandbox_refresh_expires_at = now()->addSeconds((int)$refreshExpiresIn);
            } else {
                if ($access) $raw->access_token = encrypt((string)$access);
                if ($refresh) $raw->refresh_token = encrypt((string)$refresh);
                if (is_numeric($expiresIn)) $raw->expires_at = now()->addSeconds((int)$expiresIn);
                if (is_numeric($refreshExpiresIn)) $raw->refresh_expires_at = now()->addSeconds((int)$refreshExpiresIn);
            }

            $raw->account = $result['body']['account'] ?? $raw->account;
            $raw->country = $result['body']['country'] ?? $raw->country;

            try {
                $raw->save();
            } catch (\Throwable $e) {
                // ignore — tokenResult will surface in UI
            }
        }

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => $isSandbox ? 'Sandbox Token Create' : 'Token Create',
            'data' => $result,
        ]);
    }

    public function tokenRefresh(Request $request, LazadaClient $client)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();

        $isSandbox = $request->has('sandbox')
            ? $request->boolean('sandbox')
            : (($setting->mode ?? 'live') === 'sandbox');

        $appKey = $isSandbox ? ($setting->sandbox_app_key ?? null) : ($setting->app_key ?? null);
        $appSecret = $isSandbox ? ($setting->sandbox_app_secret ?? null) : ($setting->app_secret ?? null);
        $refreshToken = $isSandbox ? ($setting->sandbox_refresh_token ?? null) : ($setting->refresh_token ?? null);

        if (!$setting || !$setting->region || !$appKey || !$appSecret || !$refreshToken) {
            $modeLabel = $isSandbox ? 'Sandbox' : 'Production';
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => "Region, {$modeLabel} App Key, {$modeLabel} App Secret, and a {$modeLabel} Refresh Token are required."],
            ]);
        }

        $apiPath = '/auth/token/refresh';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$appKey,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'refresh_token' => (string)$refreshToken,
            'grant_type' => 'refresh_token',
        ];

        $params['sign'] = $client->sign($apiPath, $params, (string)$appSecret);

        $result = $client->post((string)$setting->region, $apiPath, $params, $isSandbox ? 'sandbox' : 'live');

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $expiresIn = $result['body']['expires_in'] ?? null;

            $raw = LazadaSetting::query()->first();
            if ($raw && $access) {
                if ($isSandbox) {
                    $raw->sandbox_access_token = encrypt((string)$access);
                    if (is_numeric($expiresIn)) {
                        $raw->sandbox_expires_at = now()->addSeconds((int)$expiresIn);
                    }
                } else {
                    $raw->access_token = encrypt((string)$access);
                    if (is_numeric($expiresIn)) {
                        $raw->expires_at = now()->addSeconds((int)$expiresIn);
                    }
                }
                $raw->save();
                Cache::forget('lazada_sync_paused');
            }
        }

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Token Refresh',
            'data' => $result,
        ]);
    }

    public function callApi(Request $request, LazadaClient $client)
    {
        $request->validate([
            'api_path' => 'required|string',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Access Token are required to call API.'],
            ]);
        }

        $apiPath = (string)$request->input('api_path');
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string)$setting->access_token,
        ];


        // Normalize pagination params for specific Lazada endpoints
        if ($apiPath === '/category/brands/query') {
            // Lazada requires startRow + pageSize (not page_no/page_size)
            $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
            $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;

            if ($pageNo !== null && $pageSize !== null) {
                $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                $params['pageSize'] = max(1, $pageSize);
                unset($params['page_no'], $params['page_size']);
            } else {
                // if client already provided correct keys, ensure casing matches Lazada
                if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                    $params['pageSize'] = (int)$params['page_size'];
                    unset($params['page_size']);
                }
            }
        }

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);

        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'API Call',
            'data' => $result,
        ]);
    }

    /**
     * POC: Retrieve category tree (no auth required).
     */
    public function categoryTree(LazadaClient $client)
    {
        $setting = LazadaSetting::query()->first()?->decrypted();

        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, and App Secret are required.'],
            ]);
        }

        $apiPath = '/category/tree/get';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
        ];


        // Normalize pagination params for specific Lazada endpoints
        if ($apiPath === '/category/brands/query') {
            // Lazada requires startRow + pageSize (not page_no/page_size)
            $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
            $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;

            if ($pageNo !== null && $pageSize !== null) {
                $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                $params['pageSize'] = max(1, $pageSize);
                unset($params['page_no'], $params['page_size']);
            } else {
                // if client already provided correct keys, ensure casing matches Lazada
                if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                    $params['pageSize'] = (int)$params['page_size'];
                    unset($params['page_size']);
                }
            }
        }

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'GetCategoryTree',
            'data' => $result,
        ]);
    }

    /**
     * POC: Retrieve attributes for a category (no auth required).
     */
    public function categoryAttributes(Request $request, LazadaClient $client)
    {
        $request->validate([
            'category_id' => 'required|integer|min:1',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();

        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, and App Secret are required.'],
            ]);
        }

        $apiPath = '/category/attributes/get';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'primary_category_id' => (string)$request->input('category_id'),
        ];


        // Normalize pagination params for specific Lazada endpoints
        if ($apiPath === '/category/brands/query') {
            // Lazada requires startRow + pageSize (not page_no/page_size)
            $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
            $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;

            if ($pageNo !== null && $pageSize !== null) {
                $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                $params['pageSize'] = max(1, $pageSize);
                unset($params['page_no'], $params['page_size']);
            } else {
                // if client already provided correct keys, ensure casing matches Lazada
                if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                    $params['pageSize'] = (int)$params['page_size'];
                    unset($params['page_size']);
                }
            }
        }

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'GetCategoryAttributes',
            'data' => $result,
        ]);
    }

    /**
     * POC: Retrieve brands by page (no auth required).
     */
    public function brandsQuery(Request $request, LazadaClient $client)
    {
        $request->validate([
            'page_no' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:200',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();

        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, and App Secret are required.'],
            ]);
        }

        $apiPath = '/category/brands/query';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'page_no' => (string)($request->input('page_no', 1)),
            'page_size' => (string)($request->input('page_size', 50)),
        ];


        // Normalize pagination params for specific Lazada endpoints
        if ($apiPath === '/category/brands/query') {
            // Lazada requires startRow + pageSize (not page_no/page_size)
            $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
            $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;

            if ($pageNo !== null && $pageSize !== null) {
                $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                $params['pageSize'] = max(1, $pageSize);
                unset($params['page_no'], $params['page_size']);
            } else {
                // if client already provided correct keys, ensure casing matches Lazada
                if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                    $params['pageSize'] = (int)$params['page_size'];
                    unset($params['page_size']);
                }
            }
        }

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'GetBrandByPages',
            'data' => $result,
        ]);
    }

    /**
     * POC: Get products by SellerSku (auth required).
     */
    public function productsGet(Request $request, LazadaClient $client)
    {
        $request->validate([
            'seller_sku' => 'required|string|max:255',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Access Token are required.'],
            ]);
        }

        $apiPath = '/products/get';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string)$setting->access_token,
            'seller_sku' => (string)$request->input('seller_sku'),
        ];


        // Normalize pagination params for specific Lazada endpoints
        if ($apiPath === '/category/brands/query') {
            // Lazada requires startRow + pageSize (not page_no/page_size)
            $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
            $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;

            if ($pageNo !== null && $pageSize !== null) {
                $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                $params['pageSize'] = max(1, $pageSize);
                unset($params['page_no'], $params['page_size']);
            } else {
                // if client already provided correct keys, ensure casing matches Lazada
                if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                    $params['pageSize'] = (int)$params['page_size'];
                    unset($params['page_size']);
                }
            }
        }

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'GetProducts',
            'data' => $result,
        ]);
    }

    /**
     * POC: Build a Lazada /product/create payload from an ERP product + its options.
     * This only previews the JSON payload; it does not call Lazada.
     */
    public function productPayloadPreview(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'primary_category_id' => 'required|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'images' => 'nullable|string|max:5000',
            'description' => 'nullable|string|max:20000',
        ]);

        $payload = $this->buildProductCreatePayload(
            (int)$data['product_id'],
            (int)$data['primary_category_id'],
            isset($data['brand_id']) ? (int)$data['brand_id'] : null,
            (string)($data['images'] ?? ''),
            (string)($data['description'] ?? '')
        );

        return redirect()->route('ext.lazada.index')->withInput()->with('lazada_result', [
            'ok' => true,
            'title' => 'POC Payload Preview (product/create)',
            'data' => [
                'payload' => $payload,
            ],
        ]);
    }

    /**
     * POC: Call Lazada /product/create using the generated payload.
     * Note: Lazada catalog requirements vary by category; this is intentionally a POC.
     */
    public function productCreate(Request $request, LazadaClient $client)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'primary_category_id' => 'required|integer|min:1',
            'brand_id' => 'nullable|integer|min:1',
            'images' => 'nullable|string|max:5000',
            'description' => 'nullable|string|max:20000',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Access Token are required.'],
            ]);
        }

        $payload = $this->buildProductCreatePayload(
            (int)$data['product_id'],
            (int)$data['primary_category_id'],
            isset($data['brand_id']) ? (int)$data['brand_id'] : null,
            (string)($data['images'] ?? ''),
            (string)($data['description'] ?? '')
        );

        $apiPath = '/product/create';
        $timestamp = (string)round(microtime(true) * 1000);

        // Lazada expects the payload as a JSON string in the 'payload' parameter.
        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => (string)$setting->access_token,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];


        // Normalize pagination params for specific Lazada endpoints
        if ($apiPath === '/category/brands/query') {
            // Lazada requires startRow + pageSize (not page_no/page_size)
            $pageNo = isset($params['page_no']) ? (int)$params['page_no'] : null;
            $pageSize = isset($params['page_size']) ? (int)$params['page_size'] : null;

            if ($pageNo !== null && $pageSize !== null) {
                $params['startRow'] = max(0, ($pageNo - 1) * $pageSize);
                $params['pageSize'] = max(1, $pageSize);
                unset($params['page_no'], $params['page_size']);
            } else {
                // if client already provided correct keys, ensure casing matches Lazada
                if (isset($params['startRow']) && !isset($params['pageSize']) && isset($params['page_size'])) {
                    $params['pageSize'] = (int)$params['page_size'];
                    unset($params['page_size']);
                }
            }
        }

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->post((string)$setting->region, $apiPath, $params);

        return redirect()->route('ext.lazada.index')->withInput()->with('lazada_result', [
            'ok' => (bool)($result['ok'] ?? false),
            'title' => 'POC /product/create',
            'data' => [
                'request_payload' => $payload,
                'response' => $result,
            ],
        ]);
    }

    private function buildProductCreatePayload(int $productId, int $primaryCategoryId, ?int $brandId, string $imagesCsv, string $descriptionOverride): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $product = DB::table($pfx.'product as p')
            ->leftJoin($pfx.'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->where('p.product_id', $productId)
            ->first([
                'p.product_id','p.model','p.sku','p.price','p.quantity','p.status','pd.name','pd.description'
            ]);

        $name = html_entity_decode((string)($product->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = $descriptionOverride !== ''
            ? $descriptionOverride
            : html_entity_decode((string)($product->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $baseSku = trim((string)($product->sku ?? ''));
        if ($baseSku === '') {
            $baseSku = trim((string)($product->model ?? ''));
        }
        if ($baseSku === '') {
            $baseSku = 'P'.$productId;
        }

        $basePrice = (float)($product->price ?? 0);
        $baseQty = (int)($product->quantity ?? 0);

        $images = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$imagesCsv))));

        // Gather option values for this product
        $rows = DB::table($pfx.'product_option_value as pov')
            ->join($pfx.'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                    ->where('od.language_id', '=', $langId);
            })
            ->join($pfx.'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', $productId)
            ->orderBy('pov.option_id')
            ->orderBy('pov.option_value_id')
            ->get([
                'pov.option_id',
                'pov.option_value_id',
                'pov.sku',
                'pov.quantity',
                'pov.price',
                'pov.price_prefix',
                'od.name as option_name',
                'ovd.name as option_value_name',
            ]);

        $options = [];
        foreach ($rows as $r) {
            $optName = html_entity_decode((string)$r->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $valName = html_entity_decode((string)$r->option_value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (!isset($options[$optName])) {
                $options[$optName] = [];
            }

            $options[$optName][] = [
                'option_id' => (int)$r->option_id,
                'option_value_id' => (int)$r->option_value_id,
                'name' => $valName,
                'sku' => (string)($r->sku ?? ''),
                'quantity' => $r->quantity !== null ? (int)$r->quantity : null,
                'price' => $r->price !== null ? (float)$r->price : null,
                'price_prefix' => (string)($r->price_prefix ?? '+'),
            ];
        }

        $skuRows = $this->buildVariantSkus($baseSku, $basePrice, $baseQty, $options);

        // Minimal POC payload shape.
        return [
            'Request' => [
                'Product' => [
                    'PrimaryCategory' => $primaryCategoryId,
                    'Attributes' => array_filter([
                        'name' => $name,
                        'description' => $desc,
                        'brand_id' => $brandId ? (string)$brandId : null,
                    ], fn($v) => $v !== null && $v !== ''),
                    'Images' => $images ? ['Image' => $images] : null,
                    'Skus' => [
                        'Sku' => $skuRows,
                    ],
                ],
            ],
        ];
    }

    private function buildVariantSkus(string $baseSku, float $basePrice, int $baseQty, array $options): array
    {
        if (count($options) === 0) {
            return [[
                'SellerSku' => $baseSku,
                'quantity' => $baseQty,
                'price' => $basePrice,
            ]];
        }

        $groups = array_values($options);
        $groupNames = array_keys($options);

        // Single option -> one SKU per value
        if (count($groups) === 1) {
            $out = [];
            foreach ($groups[0] as $v) {
                $sku = trim((string)($v['sku'] ?? ''));
                if ($sku === '') {
                    $sku = $baseSku.'-'.$v['option_value_id'];
                }

                $price = $basePrice;
                if ($v['price'] !== null) {
                    $price = ($v['price_prefix'] ?? '+') === '-' ? ($price - (float)$v['price']) : ($price + (float)$v['price']);
                }

                $qty = $v['quantity'] !== null ? (int)$v['quantity'] : $baseQty;

                $out[] = [
                    'SellerSku' => $sku,
                    'quantity' => $qty,
                    'price' => $price,
                    // Not part of Lazada schema; included to help us verify mapping in the POC.
                    'variation' => [
                        $groupNames[0] => $v['name'],
                    ],
                ];
            }
            return $out;
        }

        // Multi option -> Cartesian product
        $combos = [[]];
        foreach ($groups as $gi => $values) {
            $tmp = [];
            foreach ($combos as $partial) {
                foreach ($values as $v) {
                    $tmp[] = array_merge($partial, [[
                        'group' => $groupNames[$gi],
                        'value' => $v,
                    ]]);
                }
            }
            $combos = $tmp;
        }

        $skus = [];
        foreach ($combos as $combo) {
            $ids = [];
            $var = [];
            $price = $basePrice;
            $qty = $baseQty;

            foreach ($combo as $item) {
                $v = $item['value'];
                $ids[] = (int)$v['option_value_id'];
                $var[$item['group']] = $v['name'];

                if ($v['price'] !== null) {
                    $price = ($v['price_prefix'] ?? '+') === '-' ? ($price - (float)$v['price']) : ($price + (float)$v['price']);
                }
                if ($v['quantity'] !== null) {
                    $qty = min($qty, (int)$v['quantity']);
                }
            }

            $skus[] = [
                'SellerSku' => $baseSku.'-'.implode('-', $ids),
                'quantity' => $qty,
                'price' => $price,
                // Not part of Lazada schema; included to help us verify mapping in the POC.
                'variation' => $var,
            ];
        }

        return $skus;
    }

    private function doTokenCreate(LazadaClient $client, string $region, string $appKey, string $appSecret, string $code, string $mode = 'live'): array
    {
        $apiPath = '/auth/token/create';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => trim($appKey),
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'code' => trim($code),
            'grant_type' => 'authorization_code',
        ];

        $params['sign'] = $client->sign($apiPath, $params, trim($appSecret));

        return $client->post($region, $apiPath, $params, $mode);
    }

    
    // ------------------------------
    // POC: Orders + Waybill (PDF)
    // ------------------------------
    public function ordersGet(Request $request, LazadaClient $client)
    {
        $data = $request->validate([
            'update_after' => 'nullable|string|max:64',
            'limit' => 'nullable|integer|min:1|max:100',
            'offset' => 'nullable|integer|min:0|max:5000',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Access Token are required.'],
            ]);
        }

        $params = array_filter([
            'update_after' => trim((string)($data['update_after'] ?? '')) !== '' ? trim((string)$data['update_after']) : null,
            'sort_direction' => 'DESC',
            'limit' => (int)($data['limit'] ?? 20),
            'offset' => (int)($data['offset'] ?? 0),
        ], fn($v) => $v !== null && $v !== '');

        $res = $this->runSignedApiCall(
            $client,
            (string)$setting->region,
            (string)$setting->app_key,
            (string)$setting->app_secret,
            (string)$setting->access_token,
            'GET',
            '/orders/get',
            true,
            $params,
            'poc'
        );

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)($res['ok'] ?? false),
            'title' => 'GetOrders',
            'data' => $res,
        ]);
    }

    public function orderItemsGet(Request $request, LazadaClient $client)
    {
        $data = $request->validate([
            'order_id' => 'required|string|max:64',
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Access Token are required.'],
            ]);
        }

        $res = $this->runSignedApiCall(
            $client,
            (string)$setting->region,
            (string)$setting->app_key,
            (string)$setting->app_secret,
            (string)$setting->access_token,
            'GET',
            '/order/items/get',
            true,
            ['order_id' => (string)$data['order_id']],
            'poc'
        );

        return redirect()->route('ext.lazada.index')->with('lazada_result', [
            'ok' => (bool)($res['ok'] ?? false),
            'title' => 'GetOrderItems',
            'data' => $res,
        ]);
    }

    public function awbPdfGet(Request $request, LazadaClient $client)
    {
        $data = $request->validate([
            'order_item_ids' => 'required|string|max:2000', // comma-separated
        ]);

        $setting = LazadaSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Access Token are required.'],
            ]);
        }

        $res = $this->runSignedApiCall(
            $client,
            (string)$setting->region,
            (string)$setting->app_key,
            (string)$setting->app_secret,
            (string)$setting->access_token,
            'GET',
            '/order/document/awb/pdf/get',
            true,
            ['order_item_ids' => trim((string)$data['order_item_ids'])],
            'poc'
        );

        if (!($res['ok'] ?? false)) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Get AWB PDF',
                'data' => $res,
            ]);
        }

        $body = $res['body'] ?? null;
        $b64 = $this->findBase64Document($body);
        if (!$b64) {
            // If Lazada returns a URL or non-base64 structure, just show raw response.
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => true,
                'title' => 'Get AWB PDF (response)',
                'data' => $res,
            ]);
        }

        $bin = base64_decode($b64, true);
        if ($bin === false) {
            return redirect()->route('ext.lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Get AWB PDF',
                'data' => ['message' => 'Failed to decode PDF from response.', 'response' => $res],
            ]);
        }

        $filename = 'lazada_awb_' . date('Ymd_His') . '.pdf';
        return response($bin, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function findBase64Document($body): ?string
    {
        // Common patterns observed across Lazada document APIs
        $candidates = [];

        if (is_string($body)) {
            $candidates[] = $body;
        } elseif (is_array($body)) {
            // direct keys
            foreach (['data', 'document', 'pdf', 'content'] as $k) {
                if (isset($body[$k]) && is_string($body[$k])) {
                    $candidates[] = $body[$k];
                }
            }
            // nested common
            foreach (['result', 'module', 'body'] as $k) {
                if (isset($body[$k]) && is_array($body[$k])) {
                    foreach (['data', 'document', 'pdf', 'content'] as $kk) {
                        if (isset($body[$k][$kk]) && is_string($body[$k][$kk])) {
                            $candidates[] = $body[$k][$kk];
                        }
                    }
                }
            }
        }

        foreach ($candidates as $v) {
            $v = trim($v);
            if ($v === '') continue;
            // very loose base64 check (avoid false positives)
            if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $v) && strlen($v) > 200) {
                return $v;
            }
        }

        return null;
    }


}
