<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MainPanelClient
{
    /**
     * Forward a newly created order to the main panel.
     * Pass an array with product/package/customer/payment data.
     *
     * Returns ['ok' => bool, 'main_order_id' => ?string, 'message' => ?string]
     */
    public static function forwardOrder(array $order): array
    {
        $url = rtrim(config('tastnow.functions_url'), '/') . '/tastnow-receive-order';

        try {
            $resp = Http::timeout(20)
                ->withHeaders([
                    'X-Api-Key'    => config('tastnow.shared_secret'),
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $order);

            if ($resp->successful()) {
                return ['ok' => true] + $resp->json();
            }
            Log::error('forwardOrder failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return ['ok' => false, 'message' => $resp->body()];
        } catch (\Throwable $e) {
            Log::error('forwardOrder exception', ['err' => $e->getMessage()]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
