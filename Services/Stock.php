<?php

namespace Paymenter\Extensions\Others\Pricing\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Others\Pricing\Pricing;

class Stock
{
    /** E-mail once when a product's stock drops to the warning level (or runs out). */
    public static function checkLow(Product $p, $old): void
    {
        try {
            $ext = Pricing::instance();
            $to = $ext->str('low_stock_email');
            $level = $ext->lowStock();
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || $p->stock === null) {
                return;
            }
            $was = $old === null ? PHP_INT_MAX : (int) $old;
            $now = (int) $p->stock;
            $crossed = ($was > $level && $now <= $level) || ($was > 0 && $now === 0);
            if (!$crossed) {
                return;
            }
            $text = $now === 0
                ? "\"{$p->name}\" is out of stock: customers can no longer order it."
                : "\"{$p->name}\" has only {$now} unit(s) left (warning level: {$level}).";
            $text .= "\n\nAdd units in Admin → Pricing → Prices & stock:\n" . url('/admin/pricing-prices') . '?search=' . rawurlencode($p->name);
            Mail::raw($text, fn ($m) => $m->to($to)->subject(($now === 0 ? 'Out of stock: ' : 'Low stock: ') . $p->name));
        } catch (\Throwable $e) {
            Log::warning('Pricing: low stock e-mail not sent: ' . $e->getMessage());
        }
    }
}
