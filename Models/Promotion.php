<?php

namespace Paymenter\Extensions\Others\Pricing\Models;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Model;

/** A promotion = a Paymenter coupon (discount, dates, limits, products) + how it is shown and applied. */
class Promotion extends Model
{
    protected $table = 'ext_pricing_promotions';

    protected $fillable = ['coupon_id', 'name', 'auto_apply', 'show_badge', 'note'];

    protected $casts = ['auto_apply' => 'boolean', 'show_badge' => 'boolean'];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
}
