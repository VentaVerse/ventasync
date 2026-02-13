<?php

namespace App\Http\Controllers;

use App\Models\LazadaSetting;
use App\Services\Lazada\LazadaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaController extends Controller
{
    public function index()
    {
        $setting = LazadaSetting::query()->first();

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

        return view('lazada.index', [
            'setting' => $this->decryptSetting($setting),
            'defaultRedirect' => route('lazada.callback'),
            'result' => session('lazada_result'),
            'products' => $products,
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'region' => 'nullable|in:ph,sg,my,id,th,vn',
            'app_key' => 'nullable|string|max:64',
            'app_secret' => 'nullable|string|max:255',
            'redirect_uri' => 'nullable|string|max:255',
        ]);

        $setting = LazadaSetting::query()->first() ?? new LazadaSetting();

        $setting->region = $data['region'] ?? null;
        $setting->app_key = $data['app_key'] ?? null;
        $setting->redirect_uri = $data['redirect_uri'] ?? null;

        // Encrypt secret at rest
        $setting->app_secret = isset($data['app_secret']) && $data['app_secret'] !== ''
            ? encrypt(trim($data['app_secret']))
            : null;

        $setting->save();

        return redirect()->route('lazada.index')->with('lazada_result', [
            'ok' => true,
            'title' => 'Saved',
            'data' => ['message' => 'Lazada settings saved.'],
        ]);
    }

    public function redirectToAuth(LazadaClient $client)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());

        if (!$setting || !$setting->app_key || !$setting->redirect_uri) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'App Key and Redirect URI are required to authorize.'],
            ]);
        }

        // Stateless POC, but we still bind a state token to reduce accidental cross-account redirects.
        $state = Str::random(24);
        session(['lazada_oauth_state' => $state]);

        $url = $client->authUrl((string)$setting->app_key, (string)$setting->redirect_uri, $state);

        return redirect()->away($url);
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

        // Persist the auth code so it's visible in the UI even if token exchange fails.
        if ($code !== '') {
            try {
                $rawForCode = LazadaSetting::query()->first() ?? new LazadaSetting();
                $rawForCode->auth_code = encrypt($code);
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
        $setting = $this->decryptSetting(LazadaSetting::query()->first());

        if ($code !== '' && $stateOk && $setting && $setting->region && $setting->app_key && $setting->app_secret) {
            $tokenResult = $this->doTokenCreate($client, (string)$setting->region, (string)$setting->app_key, (string)$setting->app_secret, $code);

            if (($tokenResult['ok'] ?? false) && is_array($tokenResult['body'] ?? null)) {
                $access = $tokenResult['body']['access_token'] ?? null;
                $refresh = $tokenResult['body']['refresh_token'] ?? null;
                $expiresIn = $tokenResult['body']['expires_in'] ?? null;
                $refreshExpiresIn = $tokenResult['body']['refresh_expires_in'] ?? null;

                $raw = LazadaSetting::query()->first() ?? new LazadaSetting();

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

        return view('lazada.callback', [
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
        ]);

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, and App Secret are required.'],
            ]);
        }

        $result = $this->doTokenCreate($client, (string)$setting->region, (string)$setting->app_key, (string)$setting->app_secret, (string)$request->input('code'));

        return redirect()->route('lazada.index')->with('lazada_result', [
            'ok' => (bool)$result['ok'],
            'title' => 'Token Create',
            'data' => $result,
        ]);
    }

    public function tokenRefresh(LazadaClient $client)
    {
        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->refresh_token) {
            return redirect()->route('lazada.index')->with('lazada_result', [
                'ok' => false,
                'title' => 'Missing settings',
                'data' => ['message' => 'Region, App Key, App Secret, and Refresh Token are required.'],
            ]);
        }

        $apiPath = '/auth/token/refresh';
        $timestamp = (string)round(microtime(true) * 1000);

        $params = [
            'app_key' => (string)$setting->app_key,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'refresh_token' => (string)$setting->refresh_token,
            'grant_type' => 'refresh_token',
        ];

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);

        $result = $client->post((string)$setting->region, $apiPath, $params);

        if ($result['ok'] && is_array($result['body'])) {
            $access = $result['body']['access_token'] ?? null;
            $expiresIn = $result['body']['expires_in'] ?? null;

            $raw = LazadaSetting::query()->first();
            if ($raw && $access) {
                $raw->access_token = encrypt((string)$access);
                if (is_numeric($expiresIn)) {
                    $raw->expires_at = now()->addSeconds((int)$expiresIn);
                }
                $raw->save();
            }
        }

        return redirect()->route('lazada.index')->with('lazada_result', [
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

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('lazada.index')->with('lazada_result', [
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

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);

        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('lazada.index')->with('lazada_result', [
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
        $setting = $this->decryptSetting(LazadaSetting::query()->first());

        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.index')->with('lazada_result', [
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

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('lazada.index')->with('lazada_result', [
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

        $setting = $this->decryptSetting(LazadaSetting::query()->first());

        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.index')->with('lazada_result', [
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

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('lazada.index')->with('lazada_result', [
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

        $setting = $this->decryptSetting(LazadaSetting::query()->first());

        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret) {
            return redirect()->route('lazada.index')->with('lazada_result', [
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

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('lazada.index')->with('lazada_result', [
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

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('lazada.index')->with('lazada_result', [
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

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->get((string)$setting->region, $apiPath, $params);

        return redirect()->route('lazada.index')->with('lazada_result', [
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

        return redirect()->route('lazada.index')->withInput()->with('lazada_result', [
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

        $setting = $this->decryptSetting(LazadaSetting::query()->first());
        if (!$setting || !$setting->region || !$setting->app_key || !$setting->app_secret || !$setting->access_token) {
            return redirect()->route('lazada.index')->with('lazada_result', [
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

        $params['sign'] = $client->sign($apiPath, $params, (string)$setting->app_secret);
        $result = $client->post((string)$setting->region, $apiPath, $params);

        return redirect()->route('lazada.index')->withInput()->with('lazada_result', [
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

    private function doTokenCreate(LazadaClient $client, string $region, string $appKey, string $appSecret, string $code): array
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

        return $client->post($region, $apiPath, $params);
    }

    private function decryptSetting(?LazadaSetting $setting): ?object
    {
        if (!$setting) {
            return null;
        }

        $s = (object)$setting->toArray();

        foreach (['app_secret', 'auth_code', 'access_token', 'refresh_token'] as $k) {
            if (!empty($s->$k)) {
                try {
                    $s->$k = decrypt($s->$k);
                } catch (\Throwable $e) {
                    // Treat as absent rather than breaking prod pages
                    $s->$k = null;
                }
            }
        }

        return $s;
    }
}
