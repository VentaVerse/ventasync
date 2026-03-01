<?php

namespace App\Console\Commands;

use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateCurrencyRates extends Command
{
    protected $signature = 'currencies:update-rates';
    protected $description = 'Fetch latest exchange rates from frankfurter.app and update the currencies table';

    public function handle(): int
    {
        $base = Currency::where('is_default', 1)->first();

        if (!$base) {
            $this->error('No default currency configured.');
            return self::FAILURE;
        }

        $currencies = Currency::where('is_default', 0)
            ->where('status', 1)
            ->pluck('code')
            ->toArray();

        if (empty($currencies)) {
            $this->info('No active non-default currencies to update.');
            return self::SUCCESS;
        }

        $url = 'https://api.frankfurter.app/latest?from=' . $base->code . '&to=' . implode(',', $currencies);

        try {
            $response = Http::timeout(15)->get($url);

            if (!$response->successful()) {
                $this->error("API request failed: HTTP {$response->status()}");
                Log::warning("currencies:update-rates failed: HTTP {$response->status()}");
                return self::FAILURE;
            }

            $data = $response->json();
            $rates = $data['rates'] ?? [];
            $date = $data['date'] ?? 'unknown';
            $updated = 0;

            foreach ($currencies as $code) {
                if (!isset($rates[$code]) || $rates[$code] <= 0) {
                    $this->warn("  {$code}: not available — skipped");
                    continue;
                }

                $erpRate = round(1 / $rates[$code], 8);
                Currency::where('code', $code)->update(['exchange_rate' => $erpRate]);
                $updated++;
                $this->line("  {$code}: {$erpRate}");
            }

            $this->info("Updated {$updated} currency rates (source date: {$date}).");
            Log::info("currencies:update-rates: updated {$updated} rates from {$date}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to fetch rates: {$e->getMessage()}");
            Log::error("currencies:update-rates: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
