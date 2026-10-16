<?php

namespace App\Services\Orders;

use App\Extensions\ExtensionManager;
use Illuminate\Support\Facades\DB;

/**
 * Shipping the customer paid, on the channels where it is paid to the seller.
 *
 * On a marketplace (Shopee, Lazada, TikTok) the customer's shipping goes to the
 * marketplace, and what the seller pays shows up as its delivery fee. On the
 * seller's own stores, and on orders made in VentaSync, the customer pays shipping
 * to the seller, who then pays the courier. A channel says so with
 * "shipping_paid_to_seller": true in its extension.json; the manifest is read
 * from disk so orders from a disabled channel keep the same rule.
 *
 * On those channels the customer's shipping is always the seller's income; what
 * the seller paid the courier is a separate cost.
 */
final class CustomerShipping
{
    public const TOTAL_CODE = 'shipping';

    private ?array $channels = null;

    public function __construct(private readonly ExtensionManager $extensions) {}

    /** Channel ids (the part before ":") whose customers pay shipping to the seller; '' is a manual order. */
    public function channels(): array
    {
        if ($this->channels !== null) {
            return $this->channels;
        }

        $ids = [''];
        foreach ($this->extensions->getManifests() as $id => $manifest) {
            if (($manifest['shipping_paid_to_seller'] ?? false) === true) {
                $ids[] = (string) $id;
            }
        }

        return $this->channels = array_values(array_unique($ids));
    }

    public function paidToSeller(?string $orderChannel): bool
    {
        return in_array(self::channelId($orderChannel), $this->channels(), true);
    }

    /** What the customer's shipping adds to profit: the charge on a channel that pays it to the seller, zero on a marketplace. */
    public function counted(?string $orderChannel, float $charged): float
    {
        return $this->paidToSeller($orderChannel) ? max(0.0, $charged) : 0.0;
    }

    /** SQL that reads true when an order's channel pays shipping to the seller; the channel ids are quoted in place. */
    public function channelCondition(string $channelColumn): string
    {
        $pdo = DB::connection()->getPdo();
        $quoted = implode(',', array_map(fn (string $id) => $pdo->quote($id), $this->channels()));

        return "SUBSTRING_INDEX(COALESCE({$channelColumn}, ''), ':', 1) IN ({$quoted})";
    }

    private static function channelId(?string $orderChannel): string
    {
        $channel = (string) $orderChannel;
        $colon = strpos($channel, ':');

        return $colon === false ? $channel : substr($channel, 0, $colon);
    }
}
