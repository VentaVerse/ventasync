<?php

namespace Extensions\Shopee\Controllers;

use App\Http\Controllers\Controller;

use Extensions\Shopee\Models\ShopeeCategory;
use Extensions\Shopee\Models\ShopeeApiLog;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
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
        $store = ShopeeStore::defaultStore()?->decrypted();
        $auth = ShopeeStore::activeAuth($store);
        if (!$store || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            return redirect()->route('ext.shopee.categories.index')
                ->with('error', "Missing Shopee {$modeLabel} settings. Please configure Partner ID, Partner Key, Access Token, and Shop ID first.");
        }

        $out = app(\Extensions\Shopee\Services\Shopee\ShopeeStoreSetup::class)->categories($auth);

        return redirect()->route('ext.shopee.categories.index')
            ->with($out['ok'] ? 'status' : 'error', $out['message']);
    }

}
