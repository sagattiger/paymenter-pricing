<?php

namespace Paymenter\Extensions\Others\Pricing\Http;

use Closure;
use Illuminate\Http\Request;
use Paymenter\Extensions\Others\Pricing\Services\Promotions;

/** Puts a running automatic promotion on the cart (only when it has no coupon yet). */
class ApplyPromotion
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->is('admin*')) {
            try {
                Promotions::applyToCart();
            } catch (\Throwable $e) {
                \Log::warning('Pricing: promotion not applied: ' . $e->getMessage());
            }
        }

        return $next($request);
    }
}
