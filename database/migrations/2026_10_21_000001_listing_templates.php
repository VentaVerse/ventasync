<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table, string $stores, string $channel) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name', 64);
            $table->decimal('markup_percent', 8, 2)->nullable();
            $table->decimal('markup_fixed', 12, 2)->nullable();
            $table->unsignedBigInteger('watermark_template_id')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'name'], "ch_{$channel}_listing_templates_name_unique");
            $table->foreign('store_id', "ch_{$channel}_listing_templates_store_foreign")->references('id')->on($stores)->cascadeOnDelete();
        };

        Schema::create('ch_shopee_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_shopee_stores', 'shopee');
            $table->unsignedBigInteger('shopee_category_id')->nullable();
            $table->unsignedBigInteger('shopee_brand_id')->nullable();
            $table->json('logistic_ids')->nullable();
        });

        Schema::create('ch_lazada_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_lazada_stores', 'lazada');
            $table->unsignedBigInteger('primary_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('brand_name_override', 255)->nullable();
        });

        Schema::create('ch_tiktok_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_tiktok_stores', 'tiktok');
            $table->string('tiktok_category_id', 255)->nullable();
        });

        Schema::create('ch_woocommerce_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_woocommerce_stores', 'woocommerce');
            $table->unsignedBigInteger('woo_category_id')->nullable();
        });

        Schema::create('ch_ventacart_listing_templates', function (Blueprint $table) use ($common) {
            $common($table, 'ch_ventacart_stores', 'ventacart');
            $table->unsignedBigInteger('ventacart_category_id')->nullable();
        });

        foreach (['shopee', 'lazada', 'tiktok'] as $channel) {
            Schema::create("ch_{$channel}_listing_template_attributes", function (Blueprint $table) use ($channel) {
                $table->id();
                $table->unsignedBigInteger('listing_template_id');
                $table->string('attribute_key', 191);
                $table->text('value')->nullable();
                $table->timestamps();
                $table->unique(['listing_template_id', 'attribute_key'], "ch_{$channel}_lt_attributes_unique");
                $table->foreign('listing_template_id', "ch_{$channel}_lt_attributes_template_foreign")->references('id')->on("ch_{$channel}_listing_templates")->cascadeOnDelete();
            });
        }

        $this->copyGroups();
        $this->dropGroupSettings();
    }

    public const GROUP_SETTINGS = [
        'shopee' => ['shopee_category_id', 'shopee_brand_id', 'logistic_ids', 'markup_fixed', 'markup_percent', 'watermark_template_id'],
        'lazada' => ['lazada_category_id', 'brand_id', 'brand_name_override', 'markup_fixed', 'markup_percent', 'watermark_template_id'],
        'tiktok' => ['tiktok_category_id', 'markup_percent', 'markup_fixed', 'watermark_template_id'],
        'woocommerce' => ['woo_category_id', 'markup_percent', 'markup_fixed', 'watermark_template_id'],
        'ventacart' => ['ventacart_category_id', 'ventacart_brand_id', 'markup_percent', 'markup_fixed', 'watermark_template_id'],
    ];

    public function dropGroupSettings(): void
    {
        foreach (self::GROUP_SETTINGS as $channel => $columns) {
            Schema::dropIfExists("ch_{$channel}_product_group_attributes");
            $table = "ch_{$channel}_product_groups";
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($present !== []) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
    }

    public function restoreGroupSettings(): void
    {
        foreach (self::GROUP_SETTINGS as $channel => $columns) {
            $table = "ch_{$channel}_product_groups";
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'markup_percent')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($channel) {
                match ($channel) {
                    'shopee' => [$t->unsignedBigInteger('shopee_category_id')->nullable(), $t->unsignedBigInteger('shopee_brand_id')->nullable(), $t->json('logistic_ids')->nullable()],
                    'lazada' => [$t->unsignedBigInteger('lazada_category_id')->nullable(), $t->unsignedBigInteger('brand_id')->nullable(), $t->string('brand_name_override', 255)->nullable()],
                    'tiktok' => [$t->string('tiktok_category_id', 255)->nullable()],
                    'woocommerce' => [$t->unsignedBigInteger('woo_category_id')->nullable()],
                    'ventacart' => [$t->unsignedBigInteger('ventacart_category_id')->nullable(), $t->unsignedBigInteger('ventacart_brand_id')->nullable()],
                };
                $t->decimal('markup_fixed', 12, 2)->nullable();
                $t->decimal('markup_percent', 8, 2)->nullable();
                $t->unsignedBigInteger('watermark_template_id')->nullable();
            });
            if (in_array($channel, ['shopee', 'lazada', 'tiktok'], true) && ! Schema::hasTable("ch_{$channel}_product_group_attributes")) {
                Schema::create("ch_{$channel}_product_group_attributes", function (Blueprint $t) use ($channel) {
                    $t->id();
                    $t->unsignedBigInteger("{$channel}_product_group_id");
                    $t->string('attribute_key', 191);
                    $t->text('value')->nullable();
                    $t->timestamps();
                });
            }
        }
    }

    public const CHANNELS = [
        'shopee' => [
            'model' => \Extensions\Shopee\Models\ShopeeListing::class,
            'key' => 'shopee_product_group_id',
            'fields' => ['shopee_category_id' => 'shopee_category_id', 'shopee_brand_id' => 'shopee_brand_id', 'logistic_ids' => 'logistic_ids'],
            'attributes' => 'json',
        ],
        'lazada' => [
            'model' => \Extensions\Lazada\Models\LazadaListing::class,
            'key' => 'lazada_product_group_id',
            'fields' => ['lazada_category_id' => 'primary_category_id', 'brand_id' => 'brand_id', 'brand_name_override' => 'brand_name_override'],
            'attributes' => 'table',
        ],
        'tiktok' => [
            'model' => \Extensions\TikTok\Models\TikTokListing::class,
            'key' => 'tiktok_product_group_id',
            'fields' => ['tiktok_category_id' => 'tiktok_category_id'],
            'attributes' => 'json',
        ],
        'woocommerce' => [
            'model' => \Extensions\WooCommerce\Models\WooCommerceListing::class,
            'key' => 'woocommerce_product_group_id',
            'fields' => ['woo_category_id' => 'woo_category_id'],
            'attributes' => null,
        ],
        'ventacart' => [
            'model' => \Extensions\VentaCart\Models\VentaCartListing::class,
            'key' => 'ventacart_product_group_id',
            'fields' => ['ventacart_category_id' => 'ventacart_category_id'],
            'attributes' => null,
        ],
    ];

    public function copyGroups(): void
    {
        foreach (self::CHANNELS as $channel => $c) {
            $groups = "ch_{$channel}_product_groups";
            if (! Schema::hasTable($groups) || ! Schema::hasColumn($groups, 'markup_percent') || ! class_exists($c['model'])) {
                continue;
            }
            DB::transaction(function () use ($channel, $c, $groups) {
                foreach (DB::table($groups)->orderBy('id')->get() as $group) {
                    $answers = $c['attributes'] !== null
                        ? array_filter(DB::table("ch_{$channel}_product_group_attributes")->where($c['key'], $group->id)->pluck('value', 'attribute_key')->all(), fn ($v) => trim((string) $v) !== '')
                        : [];
                    $this->makeTemplate($channel, $c, $group, $answers);
                    foreach (DB::table("ch_{$channel}_product_group_products")->where($c['key'], $group->id)->whereNotNull('product_id')->pluck('product_id')->unique() as $productId) {
                        $this->fillListing($channel, $c, $group, (int) $productId, $answers);
                    }
                }
            });
        }
    }

    private function makeTemplate(string $channel, array $c, object $group, array $answers): void
    {
        $table = "ch_{$channel}_listing_templates";
        $base = mb_substr(trim((string) $group->name) ?: 'Product group ' . $group->id, 0, 58);
        $name = $base;
        for ($n = 2; DB::table($table)->where('store_id', $group->store_id)->where('name', $name)->exists(); $n++) {
            $name = $base . ' (' . $n . ')';
        }
        $row = ['store_id' => $group->store_id, 'name' => $name, 'watermark_template_id' => $group->watermark_template_id, 'created_at' => now(), 'updated_at' => now()]
            + $this->markup($group);
        foreach ($c['fields'] as $from => $to) {
            $row[$to] = $group->{$from};
        }
        if ($row['shopee_brand_id'] ?? null) {
            $row['shopee_brand_id'] = ((int) $row['shopee_brand_id']) ?: null;
        }
        $id = DB::table($table)->insertGetId(array_filter($row, fn ($v) => $v !== null));
        foreach ($answers as $key => $value) {
            DB::table("ch_{$channel}_listing_template_attributes")->insert(['listing_template_id' => $id, 'attribute_key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function fillListing(string $channel, array $c, object $group, int $productId, array $answers): void
    {
        if (! DB::table('products')->where('product_id', $productId)->exists()) {
            return;
        }
        $model = $c['model'];
        $listing = $model::query()->withoutGlobalScopes()->where('store_id', $group->store_id)->where('product_id', $productId)->orderBy('id')->first()
            ?? (new $model())->forceFill(['store_id' => $group->store_id, 'product_id' => $productId]);

        foreach ($c['fields'] as $from => $to) {
            $value = $group->{$from};
            if ($to === 'brand_id' || $to === 'brand_name_override') {
                continue;
            }
            if ($to === 'logistic_ids') {
                $groupIds = is_string($value) ? (array) json_decode($value, true) : (array) $value;
                if (empty($listing->logistic_ids) && $groupIds !== []) {
                    $listing->logistic_ids = array_values($groupIds);
                }
                continue;
            }
            $blank = $to === 'tiktok_category_id' ? trim((string) ($listing->{$to} ?? '')) === '' : (int) ($listing->{$to} ?? 0) <= 0;
            $given = $to === 'tiktok_category_id' ? trim((string) ($value ?? '')) !== '' : (int) ($value ?? 0) > 0;
            if ($blank && $given) {
                $listing->{$to} = $value;
            }
        }
        if ($channel === 'lazada' && (int) ($listing->brand_id ?? 0) <= 0 && trim((string) ($listing->brand_name_override ?? '')) === ''
            && ((int) ($group->brand_id ?? 0) > 0 || trim((string) ($group->brand_name_override ?? '')) !== '')) {
            $listing->brand_id = $group->brand_id;
            $listing->brand_name_override = $group->brand_name_override;
        }
        if ($listing->markup_percent === null && $listing->markup_fixed === null) {
            foreach ($this->markup($group) as $column => $value) {
                $listing->{$column} = $value;
            }
        }
        if (! $listing->watermark_template_id && $group->watermark_template_id) {
            $listing->watermark_template_id = $group->watermark_template_id;
        }
        if ($c['attributes'] === 'json' && $answers !== []) {
            $own = array_filter((array) ($listing->attribute_values ?? []), fn ($v) => trim((string) (is_array($v) ? implode('', $v) : $v)) !== '');
            if (array_diff_key($answers, $own) !== []) {
                $listing->attribute_values = $own + $answers;
            }
        }
        $listing->save();

        if ($c['attributes'] === 'table') {
            $own = DB::table('ch_lazada_listing_attributes')->where('lazada_listing_id', $listing->id)->pluck('value', 'attribute_key')->all();
            foreach ($answers as $key => $value) {
                if (! array_key_exists($key, $own)) {
                    DB::table('ch_lazada_listing_attributes')->insert(['lazada_listing_id' => $listing->id, 'attribute_key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
                } elseif (trim((string) $own[$key]) === '') {
                    DB::table('ch_lazada_listing_attributes')->where('lazada_listing_id', $listing->id)->where('attribute_key', $key)->update(['value' => $value, 'updated_at' => now()]);
                }
            }
        }
    }

    private function markup(object $group): array
    {
        $percent = $group->markup_percent !== null ? (float) $group->markup_percent : null;
        $fixed = $group->markup_fixed !== null ? (float) $group->markup_fixed : null;

        return ($percent ?: 0) != 0 || ($fixed ?: 0) != 0 ? ['markup_percent' => $percent, 'markup_fixed' => $fixed] : [];
    }

    public function down(): void
    {
        $this->restoreGroupSettings();
        foreach (['shopee', 'lazada', 'tiktok'] as $channel) {
            Schema::dropIfExists("ch_{$channel}_listing_template_attributes");
        }
        foreach (['shopee', 'lazada', 'tiktok', 'woocommerce', 'ventacart'] as $channel) {
            Schema::dropIfExists("ch_{$channel}_listing_templates");
        }
    }
};
