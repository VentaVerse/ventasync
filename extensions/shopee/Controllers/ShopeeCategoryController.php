<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopeeCategoryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->query('q', ''));

        $query = ShopeeCategory::query();

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%' . $q . '%');

                if (ctype_digit($q)) {
                    $sub->orWhere('category_id', (int)$q);
                }
            });
        }

        $categories = $query
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('ext-shopee::categories.index', [
            'categories' => $categories,
            'q' => $q,
        ]);
    }

    public function fetch(Request $request, ShopeeClient $client)
    {
        $setting = ShopeeSetting::defaultStore()?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.categories.index')
                ->with('error', "Missing Shopee {$modeLabel} settings. Please configure Partner ID, Partner Key, Access Token, and Shop ID first.");
        }

        $out = app(\Extensions\shopee\Services\Shopee\ShopeeStoreSetup::class)->categories($auth);

        return redirect()->route('ext.shopee.categories.index')
            ->with($out['ok'] ? 'status' : 'error', $out['message']);
    }

}
