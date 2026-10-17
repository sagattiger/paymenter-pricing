<?php

namespace Paymenter\Extensions\Others\Pricing\Services;

use App\Models\Currency;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Others\Pricing\Support\Math;

/**
 * Prices & stock editor. Cells are keyed:
 *   "p{plan}_{CUR}_price" / "p{plan}_{CUR}_setup"  → plan price / setup fee in a currency ('' = no price)
 *   "s{product}"                                     → stock ('' = unlimited)
 */
class PriceEditor
{
    /** Currency codes, default currency first. */
    public static function currencies(): array
    {
        $default = (string) config('settings.default_currency');

        return Currency::pluck('code')->sortBy(fn ($c) => [$c === $default ? 0 : 1, $c])->values()->all();
    }

    /**
     * Products matching the filters, with their paid plans and prices.
     *
     * @param  array{search?: string, category?: ?int, family?: ?string, period?: string, hidden?: bool, stock?: string, missing?: string}  $f
     */
    public static function products(array $f, int $lowStock = 3): Collection
    {
        $q = Product::query()->with(['category', 'plans' => fn ($q) => $q->where('type', '!=', 'free')->orderBy('sort')->orderBy('id'), 'plans.prices'])
            ->orderBy('category_id')->orderBy('sort')->orderBy('name');
        if (($s = trim((string) ($f['search'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('name', 'like', '%' . $s . '%')->orWhere('slug', 'like', '%' . $s . '%'));
        }
        if (!empty($f['category'])) {
            $q->where('category_id', (int) $f['category']);
        }
        if (!empty($f['family']) && class_exists('\\Paymenter\\Extensions\\Others\\Marketplace\\Models\\Item')) {
            $q->whereIn('id', \Paymenter\Extensions\Others\Marketplace\Models\Item::where('plan_group', $f['family'])->pluck('product_id'));
        }
        if (empty($f['hidden'])) {
            // hidden products are often sold through the Marketplace: include them unless asked otherwise
        } elseif ($f['hidden'] === 'visible') {
            $q->where('hidden', false);
        } elseif ($f['hidden'] === 'hidden') {
            $q->where('hidden', true);
        }
        if (($f['stock'] ?? '') === 'low') {
            $q->whereNotNull('stock')->where('stock', '<=', $lowStock);
        } elseif (($f['stock'] ?? '') === 'limited') {
            $q->whereNotNull('stock');
        }
        $products = $q->get();
        $period = $f['period'] ?? '';
        if ($period !== '') {
            foreach ($products as $p) {
                $p->setRelation('plans', $p->plans->filter(fn (Plan $pl) => self::periodKey($pl) === $period)->values());
            }
            $products = $products->filter(fn ($p) => $p->plans->isNotEmpty())->values();
        }
        if (($cur = $f['missing'] ?? '') !== '') {
            $products = $products->filter(fn ($p) => $p->plans->contains(fn ($pl) => !$pl->prices->contains('currency_code', $cur)))->values();
        }

        return $products;
    }

    /** "1-month", "12-month", "one-time"… for the period filter. */
    public static function periodKey(Plan $plan): string
    {
        if ($plan->type === 'one-time') {
            return 'one-time';
        }

        return ((int) round(Math::months((int) $plan->billing_period, (string) $plan->billing_unit))) . '-month';
    }

    public static function periodLabel(Plan $plan): string
    {
        if ($plan->type === 'one-time') {
            return 'One-time';
        }
        $m = (int) round(Math::months((int) $plan->billing_period, (string) $plan->billing_unit));

        return match (true) {
            $m === 1 => 'Monthly',
            $m === 3 => 'Quarterly',
            $m === 6 => 'Every 6 months',
            $m === 12 => 'Yearly',
            $m === 24 => 'Every 2 years',
            $m === 36 => 'Every 3 years',
            $m > 0 => 'Every ' . $plan->billing_period . ' ' . $plan->billing_unit . 's',
            default => (string) $plan->name,
        };
    }

    /** Current values of all cells for these products. */
    public static function snapshot(Collection $products, array $currencies): array
    {
        $cells = [];
        foreach ($products as $p) {
            $cells['s' . $p->id] = $p->stock === null ? '' : (string) (int) $p->stock;
            foreach ($p->plans as $plan) {
                foreach ($currencies as $cur) {
                    $price = $plan->prices->firstWhere('currency_code', $cur);
                    $cells["p{$plan->id}_{$cur}_price"] = $price ? self::fmt((float) $price->price) : '';
                    $cells["p{$plan->id}_{$cur}_setup"] = $price && (float) $price->setup_fee > 0 ? self::fmt((float) $price->setup_fee) : '';
                }
            }
        }

        return $cells;
    }

    public static function fmt(?float $v): string
    {
        return $v === null ? '' : number_format($v, 2, '.', '');
    }

    /**
     * Bulk tool: returns the cells with new values for the selected plans (prices) or products (stock).
     *
     * @param  array{target: string, currency?: string, op: string, value: mixed, round?: string, source?: string}  $t
     *         target: price | setup | stock;  stock ops: set, add, unlimited
     */
    public static function bulk(array $cells, Collection $products, array $planIds, array $productIds, array $t): array
    {
        $value = Math::parse($t['value'] ?? '');
        $value = $value === false || $value === null ? 0.0 : $value;
        if (($t['target'] ?? '') === 'stock') {
            foreach ($productIds as $pid) {
                $key = 's' . $pid;
                if (!array_key_exists($key, $cells)) {
                    continue;
                }
                $old = $cells[$key] === '' ? null : (int) $cells[$key];
                $cells[$key] = match ($t['op']) {
                    'unlimited' => '',
                    'add' => (string) max(0, ($old ?? 0) + (int) $value),
                    default => (string) max(0, (int) $value),
                };
            }

            return $cells;
        }
        $field = ($t['target'] ?? 'price') === 'setup' ? 'setup' : 'price';
        $cur = (string) ($t['currency'] ?? '');
        $plans = $products->flatMap(fn ($p) => $p->plans->map(fn ($pl) => [$p, $pl]))->keyBy(fn ($x) => $x[1]->id);
        foreach ($planIds as $planId) {
            if (!isset($plans[$planId])) {
                continue;
            }
            [$product, $plan] = $plans[$planId];
            $key = "p{$planId}_{$cur}_{$field}";
            if (!array_key_exists($key, $cells)) {
                continue;
            }
            if ($t['op'] === 'clear') {
                $cells[$key] = '';
                if ($field === 'price') {
                    $cells["p{$planId}_{$cur}_setup"] = '';
                }
                continue;
            }
            $old = Math::parse($cells[$key]);
            $old = $old === false ? null : $old;
            $source = null;
            $months = 1;
            if ($t['op'] === 'convert') {
                $s = Math::parse($cells["p{$planId}_" . ($t['source'] ?? '') . "_{$field}"] ?? '');
                $source = $s === false ? null : $s;
            } elseif ($t['op'] === 'months') {
                $monthly = $product->plans->first(fn ($pl) => self::periodKey($pl) === '1-month');
                if (!$monthly || $monthly->id === $plan->id) {
                    continue;
                }
                $s = Math::parse($cells["p{$monthly->id}_{$cur}_{$field}"] ?? '');
                $source = $s === false ? null : $s;
                $months = Math::months((int) $plan->billing_period, (string) $plan->billing_unit);
            }
            $new = Math::apply($t['op'], $old, $value, $source, $months);
            if ($new !== null) {
                $cells[$key] = self::fmt(Math::round($new, $t['round'] ?? 'cents', $cur));
            }
        }

        return $cells;
    }

    /** Problems that block saving: "plan 12 BRL price: 'abc' is not a number", setup without price… */
    public static function problems(array $cells): array
    {
        $out = [];
        foreach ($cells as $key => $v) {
            if (str_starts_with($key, 's')) {
                if ($v !== '' && (!ctype_digit((string) $v))) {
                    $out[$key] = 'Stock must be a whole number or empty (unlimited)';
                }
                continue;
            }
            if (Math::parse($v) === false) {
                $out[$key] = '"' . $v . '" is not a number';
            } elseif (str_ends_with($key, '_setup') && $v !== '' && (float) Math::parse($v) > 0) {
                $priceKey = substr($key, 0, -6) . '_price';
                if (($cells[$priceKey] ?? '') === '') {
                    $out[$key] = 'A setup fee needs a price in the same currency';
                }
            }
        }

        return $out;
    }

    /** Products (with paid plans and prices) for a set of product / plan ids. */
    public static function load(array $productIds, array $planIds = []): Collection
    {
        if ($planIds) {
            $productIds = array_merge($productIds, Plan::whereIn('id', $planIds)->where('priceable_type', Product::class)->pluck('priceable_id')->all());
        }

        return Product::with(['plans' => fn ($q) => $q->where('type', '!=', 'free'), 'plans.prices'])->whereIn('id', array_unique(array_map('intval', $productIds)))->get();
    }

    /** Ids in a set of cell keys: [productIds, planIds]. */
    public static function ids(array $keys): array
    {
        $products = $plans = [];
        foreach ($keys as $k) {
            if (preg_match('/^s(\d+)$/', $k, $m)) {
                $products[] = (int) $m[1];
            } elseif (preg_match('/^p(\d+)_/', $k, $m)) {
                $plans[] = (int) $m[1];
            }
        }

        return [array_values(array_unique($products)), array_values(array_unique($plans))];
    }

    /**
     * Writes the edited cells ($dirty: key => value) that differ from what is stored now.
     * Every change is logged so the save can be undone.
     *
     * @return array{batch: ?string, changes: int, problems: array}
     */
    public static function save(array $dirty, ?int $userId): array
    {
        [$productIds, $planIds] = self::ids(array_keys($dirty));
        $original = self::snapshot(self::load($productIds, $planIds), self::currencies());
        $cells = array_merge($original, array_intersect_key($dirty, $original));
        $problems = self::problems(array_intersect_key($cells, $dirty + array_flip(array_map(fn ($k) => preg_replace('/_price$/', '_setup', $k), array_keys($dirty)))));
        if ($problems) {
            return ['batch' => null, 'changes' => 0, 'problems' => $problems];
        }
        $changed = array_filter($cells, fn ($v, $k) => array_key_exists($k, $dirty) && self::norm($k, $v) !== self::norm($k, $original[$k]), ARRAY_FILTER_USE_BOTH);
        if (!$changed) {
            return ['batch' => null, 'changes' => 0, 'problems' => []];
        }
        $batch = (string) Str::uuid();

        DB::transaction(function () use ($changed, $cells, $original, $userId, $batch) {
            $log = function (array $row) use ($userId, $batch) {
                DB::table('ext_pricing_changes')->insert($row + ['batch' => $batch, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            };
            // group price + setup of the same plan/currency
            $pairs = [];
            foreach ($changed as $key => $v) {
                if (preg_match('/^s(\d+)$/', $key, $m)) {
                    $product = Product::find((int) $m[1]);
                    if ($product) {
                        $new = $v === '' ? null : (int) $v;
                        $log(['product_id' => $product->id, 'field' => 'stock', 'old_value' => $product->stock, 'new_value' => $new]);
                        $product->stock = $new;
                        $product->save();
                    }
                } elseif (preg_match('/^p(\d+)_([A-Z]{3})_(price|setup)$/', $key, $m)) {
                    $pairs[$m[1] . '_' . $m[2]] = [(int) $m[1], $m[2]];
                }
            }
            foreach ($pairs as [$planId, $cur]) {
                $plan = Plan::find($planId);
                if (!$plan) {
                    continue;
                }
                $price = Price::where('plan_id', $planId)->where('currency_code', $cur)->first();
                $newPrice = Math::parse($cells["p{$planId}_{$cur}_price"] ?? '');
                $newSetup = Math::parse($cells["p{$planId}_{$cur}_setup"] ?? '');
                $base = ['product_id' => $plan->priceable_id, 'plan_id' => $planId, 'currency' => $cur];
                if ($newPrice === null || $newPrice === false) {
                    if ($price) {
                        $log($base + ['field' => 'price', 'old_value' => $price->price, 'new_value' => null]);
                        $log($base + ['field' => 'setup_fee', 'old_value' => $price->setup_fee, 'new_value' => null]);
                        $price->delete();
                    }
                    continue;
                }
                $setup = $newSetup === null || $newSetup === false ? 0.0 : $newSetup;
                $log($base + ['field' => 'price', 'old_value' => $price?->price, 'new_value' => $newPrice]);
                $log($base + ['field' => 'setup_fee', 'old_value' => $price?->setup_fee, 'new_value' => $setup]);
                $price ??= new Price(['plan_id' => $planId, 'currency_code' => $cur]);
                $price->plan_id = $planId;
                $price->currency_code = $cur;
                $price->price = $newPrice;
                $price->setup_fee = $setup;
                $price->save();
            }
        });
        self::flushCatalog();

        return ['batch' => $batch, 'changes' => count($changed), 'problems' => []];
    }

    private static function norm(string $key, $v): string
    {
        if (str_starts_with($key, 's')) {
            return trim((string) $v);
        }
        $n = Math::parse($v);

        return $n === null || $n === false ? trim((string) $v) : number_format($n, 2, '.', '');
    }

    /** The newest save that hasn't been undone: [batch, when, changes, who]. */
    public static function lastBatch(): ?array
    {
        $row = DB::table('ext_pricing_changes')->whereNull('undone_at')->orderByDesc('id')->first();
        if (!$row) {
            return null;
        }
        $n = DB::table('ext_pricing_changes')->where('batch', $row->batch)->whereIn('field', ['price', 'stock'])->count();

        return ['batch' => $row->batch, 'at' => $row->created_at, 'changes' => $n, 'user' => $row->user_id ? optional(\App\Models\User::find($row->user_id))->name : null];
    }

    /** Puts back the values from before a save. */
    public static function undo(string $batch): int
    {
        $rows = DB::table('ext_pricing_changes')->where('batch', $batch)->whereNull('undone_at')->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        DB::transaction(function () use ($rows, $batch) {
            $prices = [];
            foreach ($rows as $r) {
                if ($r->field === 'stock') {
                    $p = Product::find($r->product_id);
                    if ($p) {
                        $p->stock = $r->old_value === null ? null : (int) $r->old_value;
                        $p->save();
                    }
                } else {
                    $prices[$r->plan_id . '_' . $r->currency][$r->field] = $r->old_value;
                    $prices[$r->plan_id . '_' . $r->currency]['plan'] = $r->plan_id;
                    $prices[$r->plan_id . '_' . $r->currency]['cur'] = $r->currency;
                }
            }
            foreach ($prices as $x) {
                $price = Price::where('plan_id', $x['plan'])->where('currency_code', $x['cur'])->first();
                if (($x['price'] ?? null) === null) {
                    $price?->delete();
                    continue;
                }
                $price ??= new Price;
                $price->plan_id = $x['plan'];
                $price->currency_code = $x['cur'];
                $price->price = $x['price'];
                $price->setup_fee = $x['setup_fee'] ?? 0;
                $price->save();
            }
            DB::table('ext_pricing_changes')->where('batch', $batch)->update(['undone_at' => now()]);
        });
        self::flushCatalog();

        return $rows->whereIn('field', ['price', 'stock'])->count();
    }

    public static function flushCatalog(): void
    {
        if (class_exists('\\Paymenter\\Extensions\\Others\\Marketplace\\Services\\Catalog')) {
            \Paymenter\Extensions\Others\Marketplace\Services\Catalog::flush();
        }
        Promotions::flush();
    }
}
