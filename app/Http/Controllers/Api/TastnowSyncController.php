<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TastnowSyncController extends Controller
{
    /**
     * Receive product (with packages) from main panel.
     * Upsert by external_id.
     */
    public function syncProduct(Request $request)
    {
        $data = $request->validate([
            'external_id' => 'required|string',
            'name'        => 'required|string',
            'category'    => 'nullable|string',
            'image_url'   => 'nullable|string',
            'is_active'   => 'boolean',
            'description' => 'nullable|string',
            'packages'    => 'array',
            'packages.*.external_id' => 'required|string',
            'packages.*.name'        => 'required|string',
            'packages.*.price'       => 'required|numeric',
            'packages.*.is_active'   => 'boolean',
        ]);

        $product = Product::updateOrCreate(
            ['external_id' => $data['external_id']],
            [
                'name'        => $data['name'],
                'category'    => $data['category']   ?? null,
                'image_url'   => $data['image_url']  ?? null,
                'description' => $data['description']?? null,
                'is_active'   => $data['is_active']  ?? true,
                'source'      => 'main_panel',
            ]
        );

        foreach (($data['packages'] ?? []) as $pkg) {
            ProductPackage::updateOrCreate(
                ['external_id' => $pkg['external_id']],
                [
                    'product_id' => $product->id,
                    'name'       => $pkg['name'],
                    'price'      => $pkg['price'],
                    'is_active'  => $pkg['is_active'] ?? true,
                ]
            );
        }

        return response()->json(['ok' => true, 'product_id' => $product->id]);
    }

    /**
     * Receive order status update from main panel.
     */
    public function orderCallback(Request $request)
    {
        $data = $request->validate([
            'external_order_id' => 'required|string',
            'status'            => 'required|in:pending,processing,complete,completed,cancel,cancelled,failed',
            'note'              => 'nullable|string',
        ]);

        $order = Order::where('external_ref', $data['external_order_id'])->first();
        if (!$order) {
            Log::warning('tastnow: order not found', $data);
            return response()->json(['ok' => false, 'error' => 'order not found'], 404);
        }

        $map = [
            'complete' => 'completed', 'completed' => 'completed',
            'cancel'   => 'cancelled', 'cancelled' => 'cancelled',
            'failed'   => 'failed',    'pending'   => 'pending',
            'processing' => 'processing',
        ];
        $order->status = $map[$data['status']] ?? $data['status'];
        if (!empty($data['note'])) $order->admin_note = $data['note'];
        $order->save();

        return response()->json(['ok' => true]);
    }
}
