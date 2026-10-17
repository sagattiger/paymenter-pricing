<?php

namespace Paymenter\Extensions\Others\Pricing\Services;

use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\Pricing\Models\Promotion;
use Paymenter\Extensions\Others\Pricing\Pricing;

/**
 * Promotions are Paymenter coupons, so Paymenter itself handles the discount on the invoice, the dates,
 * the number of orders, the per-customer limit and how many payments get the discount.
 * This class adds: automatic use in the cart, sale badges, and the admin form.
 */
class Promotions
{
    private const CACHE = 'pricing_promos:live';

    public static function flush(): void
    {
        Cache::forget(self::CACHE);
    }

    public static function all(): Collection
    {
        return Promotion::with('coupon.products')->orderByDesc('id')->get()->filter(fn ($p) => $p->coupon)->values();
    }

    public static function used(Coupon $c): int
    {
        return $c->services()->count();
    }

    /** scheduled | active | ended | sold_out */
    public static function status(Coupon $c): string
    {
        if ($c->expires_at && $c->expires_at->isPast()) {
            return 'ended';
        }
        if ($c->max_uses && self::used($c) >= $c->max_uses) {
            return 'sold_out';
        }
        if ($c->starts_at && $c->starts_at->isFuture()) {
            return 'scheduled';
        }

        return 'active';
    }

    /**
     * Promotions running now (cached 1 minute), for the cart and the badges.
     *
     * @return array<int, array{coupon_id: int, code: string, name: string, type: string, applies_to: string, percent: float, cycles: int, ends: ?int, left: ?int, auto: bool, badge: bool, products: int[]}>
     */
    public static function live(): array
    {
        return Cache::remember(self::CACHE, 60, function () {
            $out = [];
            try {
                foreach (self::all() as $p) {
                    $c = $p->coupon;
                    if (self::status($c) !== 'active') {
                        continue;
                    }
                    $out[] = [
                        'coupon_id' => $c->id,
                        'code' => $c->code,
                        'name' => $p->name,
                        'type' => (string) $c->type,
                        'applies_to' => (string) ($c->applies_to ?: 'all'),
                        'percent' => $c->type === 'percentage' ? (float) $c->value : 0.0,
                        'cycles' => (int) ($c->recurring ?? 1),
                        'ends' => $c->expires_at?->getTimestamp(),
                        'left' => $c->max_uses ? max(0, $c->max_uses - self::used($c)) : null,
                        'auto' => $p->auto_apply,
                        'badge' => $p->show_badge,
                        'products' => $c->products->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    ];
                }
            } catch (\Throwable $e) {
            }

            return $out;
        });
    }

    /** The best running promotion for a product (for badges): biggest % first. */
    public static function forProduct(int $productId, bool $badgeOnly = true): ?array
    {
        $best = null;
        foreach (self::live() as $p) {
            if (($badgeOnly && !$p['badge']) || ($p['products'] && !in_array($productId, $p['products'], true))) {
                continue;
            }
            if (!$best || self::rank($p) > self::rank($best)) {
                $best = $p;
            }
        }

        return $best;
    }

    private static function rank(array $p): float
    {
        return $p['type'] === 'free_setup' ? 0.5 : $p['percent'];
    }

    /** Puts the best automatic promotion on the visitor's cart when it has none. */
    public static function applyToCart(): void
    {
        if (!Pricing::instance()->bool('auto_apply', true) || !\Illuminate\Support\Facades\Cookie::has('cart')) {
            return;
        }
        $live = array_values(array_filter(self::live(), fn ($p) => $p['auto']));
        if (!$live) {
            return;
        }
        $cart = \App\Classes\Cart::get();
        if (!$cart->exists || $cart->coupon_id || $cart->items->isEmpty()) {
            return;
        }
        $inCart = $cart->items->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        usort($live, fn ($a, $b) => self::rank($b) <=> self::rank($a));
        foreach ($live as $p) {
            if ($p['products'] && !array_intersect($inCart, $p['products'])) {
                continue;
            }
            try {
                \App\Classes\Cart::validateCoupon($p['code']);
                $cart->coupon_id = $p['coupon_id'];
                $cart->save();
                $cart->load('coupon');

                return;
            } catch (\Throwable $e) {
                // not valid for this visitor (e.g. per-customer limit): try the next one
            }
        }
    }

    /** Admin form → coupon + promotion. Dates are in the extension's time zone. */
    public static function save(array $d, ?Promotion $promo = null): Promotion
    {
        $tz = Pricing::instance()->tz();
        $appTz = (string) config('app.timezone', 'UTC');
        $date = fn ($v) => $v ? Carbon::parse($v, $tz)->setTimezone($appTz) : null;
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        if ($code === '') {
            $code = strtoupper(Str::slug((string) $d['name'], '')) ?: 'PROMO';
            $code = substr($code, 0, 24);
        }
        $exists = Coupon::where('code', $code)->when($promo, fn ($q) => $q->where('id', '!=', $promo->coupon_id))->exists();
        if ($exists) {
            if (!empty($d['code'])) {
                throw new \InvalidArgumentException('The code "' . $code . '" is already used by another coupon.');
            }
            $code .= strtoupper(Str::random(4));
        }
        $type = $d['kind'] === 'free_setup' ? 'free_setup' : 'percentage';

        return DB::transaction(function () use ($d, $promo, $code, $type, $date) {
            $coupon = $promo?->coupon ?? new Coupon;
            $coupon->fill([
                'code' => $code,
                'type' => $type,
                'value' => $type === 'percentage' ? (float) $d['percent'] : 0,
                'applies_to' => $type === 'percentage' ? ($d['kind'] === 'all' ? 'all' : 'price') : 'setup_fee',
                'recurring' => (int) $d['cycles'],
                'max_uses' => !empty($d['max_uses']) ? (int) $d['max_uses'] : null,
                'max_uses_per_user' => !empty($d['per_user']) ? (int) $d['per_user'] : null,
                'starts_at' => $date($d['starts_at'] ?? null),
                'expires_at' => $date($d['expires_at'] ?? null),
            ]);
            $coupon->save();
            $coupon->products()->sync(array_map('intval', $d['products'] ?? []));
            $promo ??= new Promotion;
            $promo->fill(['coupon_id' => $coupon->id, 'name' => trim((string) $d['name']), 'auto_apply' => (bool) ($d['auto_apply'] ?? true), 'show_badge' => (bool) ($d['show_badge'] ?? true), 'note' => $d['note'] ?? null]);
            $promo->save();
            self::flush();
            PriceEditor::flushCatalog();

            return $promo->load('coupon.products');
        });
    }

    /** Stops it now (keeps it for the record; orders already placed keep their discount). */
    public static function end(Promotion $p): void
    {
        $p->coupon->expires_at = now();
        $p->coupon->save();
        self::flush();
    }

    /** Deletes it when nobody used it; otherwise ends it (Paymenter needs the coupon for those services' renewals). */
    public static function delete(Promotion $p): string
    {
        if ($p->coupon && self::used($p->coupon) > 0) {
            self::end($p);

            return 'ended';
        }
        DB::transaction(function () use ($p) {
            if ($p->coupon) {
                $p->coupon->products()->detach();
                $p->coupon->delete();
            }
            $p->delete();
        });
        self::flush();

        return 'deleted';
    }

    /** Badge texts for a product price (used by the Marketplace). */
    public static function badge(int $productId, ?float $amount = null): ?array
    {
        if (!Pricing::isEnabled() || !Pricing::instance()->bool('badges', true)) {
            return null;
        }
        $p = self::forProduct($productId);
        if (!$p) {
            return null;
        }
        $pt = str_starts_with(app()->getLocale(), 'pt');
        $t = fn ($en, $ptT) => $pt ? $ptT : $en;
        $out = [
            'name' => $p['name'],
            'percent' => $p['percent'],
            'label' => $p['type'] === 'free_setup' ? $t('Free setup', 'Instalação grátis') : '−' . rtrim(rtrim(number_format($p['percent'], 1, ',', ''), '0'), ',') . '%',
            'discounted' => $amount !== null && $p['percent'] > 0 && $p['applies_to'] !== 'setup_fee' ? round($amount * (1 - $p['percent'] / 100), 2) : null,
            'cycles' => $p['cycles'] === 0 ? null : ($p['cycles'] <= 1 ? $t('first payment', '1ª mensalidade') : $t('first ' . $p['cycles'] . ' payments', $p['cycles'] . ' primeiras mensalidades')),
            'ends' => null,
            'left' => $p['left'] !== null ? ($pt ? 'Restam ' . $p['left'] : 'Only ' . $p['left'] . ' left') : null,
        ];
        if ($p['ends']) {
            $end = Carbon::createFromTimestamp($p['ends'])->setTimezone(Pricing::instance()->tz());
            $hours = max(0, (int) ceil(now()->diffInMinutes($end, false) / 60));
            $days = (int) ceil($hours / 24);
            $out['ends'] = $hours < 24 ? $t('ends in ' . max(1, $hours) . ' h', 'termina em ' . max(1, $hours) . ' h')
                : ($days <= 7 ? $t('ends in ' . $days . ' days', 'termina em ' . $days . ' dias') : $t('until ', 'até ') . $end->format($pt ? 'd/m' : 'M j'));
        }

        return $out;
    }
}
