<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class MainPanelCallbackController extends Controller
{
    public function handle(Request $request)
    {
        // ---- Auth (accept env key, config key, or TASTNOW key) ----
        $expected = config('services.main_panel.key')
            ?: env('MAIN_PANEL_API_KEY')
            ?: env('TASTNOW_API_KEY')
            ?: config('services.tastnow.key');

        $provided = $request->header('x-tastnow-key')
            ?: $request->header('x-main-panel-key')
            ?: $request->input('api_key');

        if (!$expected || $provided !== $expected) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        // ---- Locate order (try several id aliases) ----
        $candidates = array_filter([
            $request->input('order_id'),
            $request->input('main_panel_order_id'),
            $request->input('main_order_id'),
            $request->input('source_order_id'),
            $request->input('website_order_id'),
            $request->input('external_order_id'),
            $request->input('external_ref'),
            $request->input('api_order_id'),
        ]);

        if (empty($candidates)) {
            return response()->json(['ok' => false, 'error' => 'order_id required'], 422);
        }

        $query = Order::query();
        $hasOrderIdCol = Schema::hasColumn('orders', 'order_id');
        $hasExtRefCol  = Schema::hasColumn('orders', 'external_ref');

        foreach (array_values($candidates) as $i => $val) {
            $cb = function ($q) use ($val, $hasOrderIdCol, $hasExtRefCol) {
                $q->where('id', $val);
                if ($hasOrderIdCol) $q->orWhere('order_id', $val);
                if ($hasExtRefCol)  $q->orWhere('external_ref', $val);
            };
            $i === 0 ? $query->where($cb) : $query->orWhere($cb);
        }

        $order = $query->first();
        if (!$order) {
            return response()->json(['ok' => false, 'error' => 'order not found'], 404);
        }

        // ---- Safe status mapping (no fatal if Status const missing) ----
        $statusConst = function (string $name, string $fallback) {
            $full = '\App\Constants\Status::' . $name;
            return defined($full) ? constant($full) : $fallback;
        };

        $rawStatus = strtolower((string) ($request->input('status') ?: $request->input('status_alias') ?: ''));
        $map = [
            'completed'  => $statusConst('ORDER_COMPLETED', 'completed'),
            'complete'   => $statusConst('ORDER_COMPLETED', 'completed'),
            'success'    => $statusConst('ORDER_COMPLETED', 'completed'),
            'delivered'  => $statusConst('ORDER_COMPLETED', 'completed'),
            'cancelled'  => $statusConst('ORDER_CANCELED',  'cancelled'),
            'canceled'   => $statusConst('ORDER_CANCELED',  'cancelled'),
            'cancel'     => $statusConst('ORDER_CANCELED',  'cancelled'),
            'refunded'   => $statusConst('ORDER_CANCELED',  'cancelled'),
            'pending'    => $statusConst('ORDER_PENDING',   'pending'),
            'processing' => $statusConst('ORDER_PROCESSING','processing'),
        ];
        if (isset($map[$rawStatus])) {
            $order->status = $map[$rawStatus];
        }

        // ---- Delivery message ----
        $message = trim((string) $request->input('delivery_message', ''));
        if ($message !== '' && Schema::hasColumn('orders', 'delivery_message')) {
            $order->delivery_message = $message;
        }

        // ---- Replacement UID ----
        $newUid = trim((string) (
            $request->input('replacement_uid')
            ?: $request->input('delivered_uid')
            ?: $request->input('game_id')
            ?: $request->input('uid')
            ?: $request->input('player_id')
            ?: ''
        ));

        if ($newUid !== '') {
            // Plain columns
            foreach (['game_id','uid','player_id','user_game_id','player_uid','account_id'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $order->{$col} = $newUid;
                }
            }

            // JSON / array columns — recursive replace + set common keys
            $jsonCols = ['account_info','account_info_to','provider_data','custom_field_values','meta','extra'];
            $casts = $order->getCasts();

            $replaceDeep = function (&$node) use (&$replaceDeep, $newUid) {
                if (!is_array($node)) return;
                foreach ($node as $k => &$v) {
                    if (is_array($v)) {
                        $replaceDeep($v);
                    } elseif (in_array(strtolower((string)$k), ['player_id','uid','game_id','user_id','user_game_id','account_id','player_uid'], true)) {
                        $v = $newUid;
                    }
                }
            };

            foreach ($jsonCols as $jsonCol) {
                if (!Schema::hasColumn('orders', $jsonCol)) continue;

                $existing = $order->{$jsonCol};
                if (is_string($existing)) {
                    $decoded = json_decode($existing, true);
                    $existing = is_array($decoded) ? $decoded : [];
                } elseif (!is_array($existing)) {
                    $existing = [];
                }

                $replaceDeep($existing);
                $existing['player_id'] = $newUid;
                $existing['uid']       = $newUid;
                $existing['game_id']   = $newUid;
                $existing['user_id']   = $newUid;

                $isArrayCast = isset($casts[$jsonCol]) && in_array($casts[$jsonCol], ['array','json','object','collection'], true);
                $order->{$jsonCol} = $isArrayCast ? $existing : json_encode($existing, JSON_UNESCAPED_UNICODE);
            }
        }

        $order->save();

        Log::info('MainPanelCallback applied', [
            'order_id' => $order->id,
            'status'   => $order->status,
            'new_uid'  => $newUid,
        ]);

        return response()->json([
            'ok' => true,
            'updated_uid' => $newUid,
            'status' => $order->status,
            'account_info' => $order->account_info ?? null,
        ]);
    }
}
