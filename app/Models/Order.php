<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'provider_data'       => 'array',
        'account_info'        => 'array',
        'account_info_to'     => 'array',
        'custom_field_values' => 'array',
        'meta'                => 'array',
        'extra'               => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'order_id');
    }

    public function voucher(): HasOne
    {
        return $this->hasOne(Voucher::class, 'order_id');
    }
}