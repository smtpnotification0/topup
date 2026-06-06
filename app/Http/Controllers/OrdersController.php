<?php

namespace App\Http\Controllers;

use App\Constants\Status;
use App\Constants\OrderStatus;
use App\Library\UddoktaPay;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variation;
use App\Models\Voucher;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrdersController extends Controller
{
    // ============== TELEGRAM CONFIG ==============
    // env() override করতে চাইলে .env এ TELEGRAM_BOT_TOKEN ও TELEGRAM_CHAT_ID দিন
    const TG_BOT_TOKEN = '7871654767:AAGgKAMasbsWBnAi5kL9VnR4tRg-J8yzA7M';
    const TG_CHAT_ID   = '-4741918127';
    // =============================================

    public function buynow(Request $request)
    {
        $variation = Variation::where('stock', '>', 0)
            ->with(['product', 'vouchers' => function ($query) {
                $query->where('status', Status::AVAILABLE);
            }])
            ->findOrFail($request->variation_id);

        $quantity = max(1, (int) $request->input('quantity', 1));

        if ($variation->product->isVoucher() && $variation->vouchers->count() < $quantity) {
            return back()->with('error', __('Sorry, this voucher is out of stock.'));
        }

        $amount_cal = round($variation->price * $quantity, 2);
        $profit_cal = number_format(max(0, $amount_cal - ($variation->buy_rate * $quantity)), 2, '.', '');
        $accountInfo = $request->input('account_info');

        $lockKey = 'order_lock:' . Auth::id() . ':' . $variation->id . ':' . md5(json_encode($accountInfo) . '|' . $quantity . '|' . $request->payment_method);
        if (!Cache::add($lockKey, 1, now()->addSeconds(5))) {
            return $this->failResponse($request, __('Duplicate order request detected. Please wait a moment.'));
        }

        $orderData = $this->buildOrderData($variation, $quantity, $amount_cal, $profit_cal, $accountInfo, Auth::id());

        if (gs()->wallet && $request->payment_method === Status::WALLET) {
            try {
                $createdOrderId = null;

                DB::transaction(function () use ($orderData, $variation, $quantity, $amount_cal, &$createdOrderId) {
                    $user = User::whereKey(Auth::id())->lockForUpdate()->firstOrFail();

                    if ($amount_cal > $user->balance) {
                        throw new Exception(__('Insufficient Balance.'));
                    }

                    $vouchers = collect();
                    if ($variation->product->isVoucher()) {
                        $vouchers = Voucher::where('status', Status::AVAILABLE)
                            ->where('variation_id', $variation->id)
                            ->limit($quantity)
                            ->orderBy('id', 'DESC')
                            ->lockForUpdate()
                            ->get();

                        if ($vouchers->count() < $quantity) {
                            throw new Exception(__('Insufficient vouchers available.'));
                        }
                    }

                    $order = Order::create($orderData);
                    $createdOrderId = $order->id;
                    $order->status = $order->product->isVoucher() ? Status::COMPLETE : Status::PROCESSING;
                    $order->save();

                    $user->balance = $user->balance - $order->amount;
                    $user->save();

                    $this->createTransaction([
                        'user_id'        => $user->id,
                        'user_gmail'     => $user->email,
                        'method'         => 'Wallet',
                        'transaction_id' => 'WAL' . strtoupper(Str::random(12)),
                        'amount'         => $amount_cal,
                        'page'           => 'check out page',
                        'order_id'       => $order->id,
                        'time_paid'      => now(),
                        'unpaid'         => 0,
                    ]);

                    if ($order->product->isVoucher()) {
                        $order->variation->decrement('stock', $vouchers->count());

                        $voucherCodes = [];
                        foreach ($vouchers as $voucher) {
                            $voucherCodes[] = is_array($voucher->code) ? implode(',', $voucher->code) : $voucher->code;
                            $voucher->status = Status::SOLD;
                            $voucher->order_id = $order->id;
                            $voucher->save();
                        }

                        $order->voucher_code = implode(', ', $voucherCodes);
                        $order->save();
                    } else {
                        $order->variation->decrement('stock', $order->quantity);
                    }

                    // 🔔 New order placed
                    $this->notifyOrderEvent($order, '🆕 New Order Placed (Wallet)', 'success');

                    $this->handleReseller($order);
                    $this->triggerAutomation($order);
                });

                $redirect = $variation->product->isVoucher() ? route('codes') : route('order.success', ['order' => $createdOrderId]);
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json(['success' => true, 'redirect_url' => $redirect, 'message' => 'Order Successful.']);
                }

                return redirect($redirect)->with('message', 'Order Successful.')->with('message_type', 'success');
            } catch (Exception $exception) {
                $this->sendNotification("⚠️ <b>Wallet Payment Failed!</b>\n👤 User: " . (Auth::user()->email ?? '-') . "\n❌ Error: " . $exception->getMessage());
                return $this->failResponse($request, $exception->getMessage());
            }
        }

        return $this->processUddoktaPay($variation, $orderData, $request);
    }

    public function paymentSuccess(Request $request)
    {
        $transactionId = $request->query('transactionId') ?? $request->query('invoice_id');
        if (empty($transactionId)) {
            return redirect()->route('orders')->with('message', 'Order failed: Transaction ID missing.')->with('message_type', 'error');
        }

        $lockKey = 'gateway_order_lock:' . md5($transactionId);
        if (!Cache::add($lockKey, 1, now()->addSeconds(10))) {
            return redirect()->route('orders')->with('message', 'Order already processing.')->with('message_type', 'info');
        }

        try {
            $data = UddoktaPay::verify_payment($transactionId);
            if (!isset($data['status']) || $data['status'] !== 'COMPLETED') {
                return redirect()->route('orders')->with('message', 'Payment not completed.')->with('message_type', 'error');
            }

            $metadata = is_array($data['metadata']) ? $data['metadata'] : json_decode($data['metadata'], true);
            if (!$metadata || ($metadata['type'] ?? null) !== 'order') {
                return redirect()->route('orders')->with('message', 'Invalid metadata.')->with('message_type', 'error');
            }

            $user = Auth::user() ?? User::find($metadata['user_id']);
            if (!$user) {
                throw new Exception('User not found.');
            }

            $gatewayTrxId = $data['transaction_id'] ?? $transactionId;
            $paymentMethod = $data['payment_method'] ?? 'UddoktaPay';

            return DB::transaction(function () use ($metadata, $gatewayTrxId, $paymentMethod, $user) {
                $existingTransaction = Transaction::where('transaction_id', $gatewayTrxId)->first();
                if ($existingTransaction) {
                    $orderId = $existingTransaction->order_id ?? null;
                    return $orderId
                        ? redirect()->route('order.success', ['order' => $orderId])->with('message', 'Order already processed.')->with('message_type', 'info')
                        : redirect()->route('orders')->with('message', 'Order already processed.')->with('message_type', 'info');
                }

                $variation = Variation::with('product')->whereKey($metadata['variation_id'])->lockForUpdate()->firstOrFail();
                $quantity = max(1, (int) ($metadata['quantity'] ?? 1));

                if ($variation->stock < $quantity) {
                    throw new Exception(__('Insufficient stock available.'));
                }

                $amount_cal = round($variation->price * $quantity, 2);
                $profit_cal = number_format(max(0, $amount_cal - ($variation->buy_rate * $quantity)), 2, '.', '');
                $orderData = $this->buildOrderData(
                    $variation,
                    $quantity,
                    $amount_cal,
                    $profit_cal,
                    $metadata['account_info'] ?? null,
                    $user->id,
                    $variation->product->isVoucher() ? Status::COMPLETE : Status::PROCESSING
                );

                $order = Order::create($orderData);

                $this->createTransaction([
                    'user_id'        => $user->id,
                    'user_gmail'     => $user->email,
                    'method'         => $paymentMethod,
                    'transaction_id' => $gatewayTrxId,
                    'amount'         => $amount_cal,
                    'page'           => 'check out page',
                    'order_id'       => $order->id,
                    'time_paid'      => now(),
                    'unpaid'         => 0,
                ]);

                if ($order->product->isVoucher()) {
                    $vouchers = Voucher::where('status', Status::AVAILABLE)
                        ->where('variation_id', $variation->id)
                        ->limit($order->quantity)
                        ->lockForUpdate()
                        ->get();

                    if ($vouchers->count() < $order->quantity) {
                        throw new Exception(__('Insufficient vouchers available.'));
                    }

                    $codes = [];
                    foreach ($vouchers as $voucher) {
                        $voucher->update(['status' => Status::SOLD, 'order_id' => $order->id]);
                        $codes[] = is_array($voucher->code) ? implode(',', $voucher->code) : $voucher->code;
                    }

                    $order->update(['voucher_code' => implode(', ', $codes)]);
                }

                $variation->decrement('stock', $order->quantity);

                // 🔔 New order placed (gateway)
                $this->notifyOrderEvent($order, "🆕 New Order Placed ({$paymentMethod})", 'success');

                $this->handleReseller($order);
                $this->triggerAutomation($order);

                return redirect($variation->product->isVoucher() ? route('codes') : route('order.success', ['order' => $order->id]))
                    ->with('message', 'Order Successful.')
                    ->with('message_type', 'success');
            });
        } catch (Exception $e) {
            $this->sendNotification("⚠️ <b>UddoktaPay Verification Failed!</b>\n❌ Error: " . $e->getMessage());
            return redirect()->route('orders')->with('message', 'Verification Error: ' . $e->getMessage())->with('message_type', 'error');
        }
    }

    private function processUddoktaPay($variation, $orderData, $request)
    {
        try {
            $user = Auth::user();
            $requestData = [
                'full_name'    => $user->name ?? 'Guest User',
                'email'        => $user->email ?? 'no-email@test.com',
                'amount'       => $orderData['amount'],
                'metadata'     => [
                    'account_info' => $request->input('account_info'),
                    'variation_id' => $variation->id,
                    'quantity'     => $request->input('quantity', 1),
                    'user_id'      => $user->id,
                    'type'         => 'order',
                ],
                'redirect_url' => route('payment.success'),
                'return_type'  => 'GET',
                'cancel_url'   => route('cancel.payment'),
            ];

            $paymentUrl = UddoktaPay::init_payment($requestData);
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => true, 'payment_url' => $paymentUrl]);
            }

            return redirect($paymentUrl);
        } catch (Exception $e) {
            $this->sendNotification("⚠️ <b>UddoktaPay Init Failed!</b>\n👤 User: " . (Auth::user()->email ?? '-') . "\n❌ Error: " . $e->getMessage());
            return $this->failResponse($request, $e->getMessage());
        }
    }

    private function buildOrderData($variation, int $quantity, $amount, $profit, $accountInfo, int $userId, $status = null): array
    {
        $accountInfoJson = is_array($accountInfo) ? json_encode($accountInfo, JSON_UNESCAPED_UNICODE) : $accountInfo;

        $data = [
            'user_id'      => $userId,
            'product_id'   => $variation->product->id,
            'variation_id' => $variation->id,
            'quantity'     => $quantity,
            'amount'       => $amount,
            'account_info' => $accountInfoJson,
        ];

        if ($status !== null) {
            $data['status'] = $status;
        }

        if (Schema::hasColumn('orders', 'profit')) {
            $data['profit'] = $profit;
        }

        if (Schema::hasColumn('orders', 'account_info_original')) {
            $data['account_info_original'] = $accountInfoJson;
        }

        if (Schema::hasColumn('orders', 'account_info_to')) {
            $data['account_info_to'] = $accountInfoJson;
        }

        if (Schema::hasColumn('orders', 'order_id_to')) {
            $data['order_id_to'] = $this->generateOrderIdTo();
        }

        return $data;
    }

    private function generateOrderIdTo(): string
    {
        do {
            $uniqueId = 'ORD' . random_int(100000, 999999);
        } while (Order::where('order_id_to', $uniqueId)->exists());

        return $uniqueId;
    }

    private function createTransaction(array $data): Transaction
    {
        foreach (['user_id', 'transaction_id', 'amount', 'order_id'] as $column) {
            if (!Schema::hasColumn('transactions', $column)) {
                throw new Exception("transactions table missing {$column} column. Please run fix-order-create.sql first.");
            }
        }

        $columns = array_flip(Schema::getColumnListing('transactions'));
        return Transaction::create(array_intersect_key($data, $columns));
    }

    private function failResponse(Request $request, string $message)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => false, 'message' => $message], 400);
        }

        return back()->with('message', $message)->with('message_type', 'error');
    }

    private function handleReseller(Order $order)
    {
        $user = $order->user;
        if ($user && method_exists($user, 'isReseller') && $user->isReseller()) {
            $percentageAmount = ($order->amount * $order->product->percentage) / 100;
            $user->increment('balance', $percentageAmount);
        }
    }

    private function triggerAutomation($order)
    {
        try {
            $order->loadMissing(['product','variation']);

            // Skip vouchers — they are delivered locally
            if ($order->product && method_exists($order->product,'isVoucher') && $order->product->isVoucher()) {
                return;
            }

            $tagline = $order->variation->provider_product_id ?? null;

            // Diagnostic flags (for debugging)
            $isTopup     = $order->product && method_exists($order->product,'isTopup') ? $order->product->isTopup() : false;
            $isAutomatic = $order->variation && method_exists($order->variation,'isAutomatic') ? $order->variation->isAutomatic() : false;
            $autoEnabled = (bool) (gs()->enable_auto_topup ?? false);
            $isProcessing= ($order->status === Status::PROCESSING);

            // 🔑 PRIMARY GATE: যদি package-এ provider_product_id (tagline) set থাকে, forward করো — অন্য flag ignore করো
            if (!empty($tagline) && $isProcessing) {
                $this->transferToNewApi($order);
                return;
            }

            // Fallback original gate
            if ($isTopup && $isAutomatic && $autoEnabled && $isProcessing) {
                $this->transferToNewApi($order);
                return;
            }

            // None matched — report exactly WHY
            $reasons = [];
            if (empty($tagline))   $reasons[] = 'provider_product_id (Auto Topup tagline) খালি';
            if (!$isTopup)         $reasons[] = 'product type ≠ topup';
            if (!$isAutomatic)     $reasons[] = 'variation isAutomatic = false';
            if (!$autoEnabled)     $reasons[] = 'Admin → Settings → enable_auto_topup OFF';
            if (!$isProcessing)    $reasons[] = 'order status ≠ processing (= ' . $order->status . ')';

            $this->notifyOrderEvent(
                $order,
                "📝 Auto-forward Skipped — Manual Action Needed
🚫 কারণ: " . implode(' | ', $reasons),
                'warning'
            );
        } catch (Exception $e) {
            \Log::error('Automation Error: ' . $e->getMessage());
            $this->notifyOrderEvent($order, '❌ Automation Error: ' . $e->getMessage(), 'error');
        }
    }

    private function transferToNewApi(Order $order)
    {
        $order->loadMissing(['product', 'variation']);

        $info = $order->account_info;
        if (is_string($info)) {
            $decoded = json_decode($info, true);
            if (is_array($decoded)) $info = $decoded;
        }
        $uid = is_array($info) ? ($info['player_id'] ?? ($info['uid'] ?? json_encode($info, JSON_UNESCAPED_UNICODE))) : $info;

        $mainPanelUrl = rtrim((string) env('MAIN_PANEL_URL', ''), '/');
        $apiKey       = (string) env('MAIN_PANEL_API_KEY', '');
        $siteUrl      = rtrim((string) env('MAIN_PANEL_SITE_URL', config('app.url')), '/');

        if ($mainPanelUrl === '' || $apiKey === '') {
            $this->notifyOrderEvent(
                $order,
                "⚠️ Main Panel Forward Config Missing\nMAIN_PANEL_URL / MAIN_PANEL_API_KEY .env এ সেট করা নেই",
                'error'
            );
            return;
        }

        // This project's external-order function receives new orders on the /create action.
        // If .env has only .../functions/v1/external-order, append /create automatically.
        $apiUrl = preg_match('~/create$~', $mainPanelUrl) ? $mainPanelUrl : $mainPanelUrl . '/create';

        $packageName = $order->variation->provider_product_id
            ?? $order->variation->title
            ?? $order->product->name
            ?? '';

        $payload = [
            'external_order_id' => (string) $order->id,
            'product_name'      => $order->product->name ?? $packageName,
            'package_name'      => $packageName,
            'game_id'           => (string) $uid,
            'amount'            => (float) $order->amount,
            'callback_url'      => $siteUrl ? $siteUrl . '/api/tastnow/order-callback' : null,
            'account_info'      => $info,
        ];

        try {
            $response = Http::timeout(20)->withHeaders([
                'x-api-key'    => $apiKey,
                'Referer'      => $siteUrl ?: url('/'),
                'Origin'       => $siteUrl ?: url('/'),
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($apiUrl, $payload);

            if ($response->successful()) {
                $order->update(['status' => OrderStatus::AUTOPROCESSING]);
                $this->notifyOrderEvent(
                    $order,
                    "✅ Order Forwarded to This Main Panel Successfully\n🆔 UID: <code>{$uid}</code>\n🔗 URL: <code>{$apiUrl}</code>",
                    'success'
                );
            } else {
                $this->notifyOrderEvent(
                    $order,
                    "❌ Main Panel Transfer FAILED\n🆔 UID: <code>{$uid}</code>\n🔗 URL: <code>{$apiUrl}</code>\n📡 HTTP: " . $response->status() . "\n📝 Response: " . substr($response->body(), 0, 500),
                    'error'
                );
            }
        } catch (Exception $e) {
            \Log::error('Main Panel Transfer Error: ' . $e->getMessage());
            $this->notifyOrderEvent($order, "⚠️ Main Panel Connection Error\n🆔 UID: <code>{$uid}</code>\n🔗 URL: <code>{$apiUrl}</code>\n❌ " . $e->getMessage(), 'error');
        }
    }

    /**
     * Send a full order-event notification with package/customer/UID details.
     */
    private function notifyOrderEvent(Order $order, string $title, string $kind = 'info')
    {
        try {
            $order->loadMissing(['user', 'product', 'variation']);

            $info = $order->account_info;
            if (is_string($info)) {
                $decoded = json_decode($info, true);
                if (is_array($decoded)) $info = $decoded;
            }
            if (is_array($info)) {
                $uidParts = [];
                foreach ($info as $k => $v) {
                    if (is_scalar($v) && $v !== '') $uidParts[] = "{$k}: {$v}";
                }
                $uidText = $uidParts ? implode(' | ', $uidParts) : '-';
            } else {
                $uidText = $info ?: '-';
            }

            $emoji = ['success' => '🟢', 'warning' => '🟡', 'error' => '🔴'][$kind] ?? '🔵';

            $msg  = "{$emoji} <b>{$title}</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "🧾 <b>Order ID:</b> #{$order->id}\n";
            $msg .= "📦 <b>Package:</b> " . ($order->variation->title ?? '-') . "\n";
            $msg .= "🛍️ <b>Product:</b> " . ($order->product->name ?? '-') . "\n";
            $msg .= "💵 <b>Amount:</b> " . number_format((float)$order->amount, 2) . "\n";
            $msg .= "📊 <b>Status:</b> " . ($order->status ?? '-') . "\n";
            $msg .= "👤 <b>Customer:</b> " . ($order->user->name ?? '-') . "\n";
            $msg .= "📧 <b>Email:</b> " . ($order->user->email ?? '-') . "\n";
            $msg .= "🆔 <b>UID / Account:</b> <code>" . htmlspecialchars($uidText, ENT_QUOTES) . "</code>\n";
            $msg .= "🕒 <b>Time:</b> " . now()->format('d M Y, h:i A');

            $this->sendNotification($msg);
        } catch (Exception $e) {
            \Log::error('notifyOrderEvent: ' . $e->getMessage());
        }
    }

    private function sendNotification($message)
    {
        try {
            // Priority: env > GeneralSettings > hardcoded constant
            $token = env('TELEGRAM_BOT_TOKEN');
            $chat  = env('TELEGRAM_CHAT_ID');

            if (!$token || !$chat) {
                try {
                    $settings = app(\App\Settings\GeneralSettings::class);
                    if (!empty($settings->botToken_1) && !empty($settings->chatId_1)) {
                        $token = $token ?: $settings->botToken_1;
                        $chat  = $chat  ?: $settings->chatId_1;
                    }
                } catch (Exception $e) { /* ignore */ }
            }

            $token = $token ?: self::TG_BOT_TOKEN;
            $chat  = $chat  ?: self::TG_CHAT_ID;

            if (!$token || !$chat) return;

            Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'                  => $chat,
                'text'                     => $message,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (Exception $e) {
            \Log::error('Telegram Notify Error: ' . $e->getMessage());
        }
    }

    public function success($order)
    {
        $order = Order::with(['product', 'variation'])
            ->where('user_id', Auth::id())
            ->findOrFail($order);

        return view('pages.order-success', ['order' => $order]);
    }
}
