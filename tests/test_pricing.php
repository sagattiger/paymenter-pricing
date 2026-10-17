<?php
/**
 * qsync Pricing test. Runs inside Paymenter and rolls back everything it creates:
 *   PAYMENTER=/opt/paymenter php tests/test_pricing.php
 */
$root = getenv('PAYMENTER') ?: '/opt/paymenter';
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Others\Pricing\Pricing;
use Paymenter\Extensions\Others\Pricing\Services\PriceEditor as E;
use Paymenter\Extensions\Others\Pricing\Services\Promotions as P;
use Paymenter\Extensions\Others\Pricing\Support\Math as M;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

echo "Math\n";
ok(round(M::apply('percent', 100, 10), 2) == 110.0 && round(M::apply('percent', 100, -15), 2) == 85.0 && M::apply('amount', 10, -20) == 0.0, 'percent / amount (never below 0)');
ok(M::apply('convert', null, 0.2, 149.9) == 149.9 * 0.2 && M::apply('months', null, 15, 30, 12) == 306.0 && M::apply('percent', null, 10) === null, 'convert / months / nothing to work from');
ok(M::round(31.37, '90') == 30.90 && M::round(31.6, '90') == 31.90 && M::round(29.45, '99') == 28.99 && M::round(0.3, '90') == 0.90, 'rounding to ,90 / ,99');
ok(M::round(29.6, 'whole') == 30.0 && M::round(29.3, 'half') == 29.5 && M::round(31, 'five') == 30.0 && M::round(31.374, 'cents') == 31.37, 'whole / half / five / cents');
ok(M::parse('1.299,90') == 1299.9 && M::parse('29.9') == 29.9 && M::parse('R$ 29,90') == 29.9 && M::parse('') === null && M::parse('abc') === false, 'parse BR and US numbers');

$ext = Pricing::instance();
$set = function (array $v) use ($ext) { $r = new ReflectionProperty($ext, 'config'); $r->setAccessible(true); $r->setValue($ext, array_merge((array) $r->getValue($ext), $v)); };

DB::beginTransaction();
try {
    echo "Prices & stock\n";
    $product = Product::with(['plans' => fn ($q) => $q->where('type', '!=', 'free'), 'plans.prices'])->get()->first(fn ($p) => $p->plans->count() >= 1);
    $plan = $product->plans->first();
    // a clean, known starting point: monthly 100 BRL, no USD; a yearly plan 1100 BRL
    Price::where('plan_id', $plan->id)->delete();
    $plan->update(['type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month']);
    Price::create(['plan_id' => $plan->id, 'currency_code' => 'BRL', 'price' => 100, 'setup_fee' => 0]);
    $yearly = $product->plans()->create(['name' => 'Yearly test', 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year']);
    Price::create(['plan_id' => $yearly->id, 'currency_code' => 'BRL', 'price' => 1100, 'setup_fee' => 0]);
    $product->stock = 5; $product->save();

    $products = E::load([$product->id]);
    $cells = E::snapshot($products, E::currencies());
    ok($cells["p{$plan->id}_BRL_price"] === '100.00' && $cells["p{$plan->id}_USD_price"] === '' && $cells['s' . $product->id] === '5', 'snapshot: BRL 100, no USD, stock 5');
    $c = E::bulk($cells, $products, [$plan->id], [], ['target' => 'price', 'currency' => 'BRL', 'op' => 'percent', 'value' => '10', 'round' => '90']);
    ok($c["p{$plan->id}_BRL_price"] === '109.90', '+10 % rounded to ,90 → 109.90');
    $c = E::bulk($c, $products, [$plan->id], [], ['target' => 'price', 'currency' => 'USD', 'op' => 'convert', 'value' => '0,2', 'source' => 'BRL', 'round' => '99']);
    ok($c["p{$plan->id}_USD_price"] === '21.99', 'USD = BRL × 0.2 rounded to ,99 → 21.99');
    $c = E::bulk($c, $products, [$yearly->id, $plan->id], [], ['target' => 'price', 'currency' => 'BRL', 'op' => 'months', 'value' => '15', 'round' => 'whole']);
    ok($c["p{$yearly->id}_BRL_price"] === '1121.00' && $c["p{$plan->id}_BRL_price"] === '109.90', 'yearly = monthly × 12 − 15 % → 1121 (monthly itself unchanged)');
    $c = E::bulk($c, $products, [], [$product->id], ['target' => 'stock', 'op' => 'add', 'value' => '10']);
    ok($c['s' . $product->id] === '15', 'stock +10 → 15');
    ok(E::bulk($c, $products, [], [$product->id], ['target' => 'stock', 'op' => 'unlimited', 'value' => ''])['s' . $product->id] === '', 'stock → unlimited');
    $bad = $c; $bad["p{$plan->id}_EUR_setup"] = '5'; $bad["p{$plan->id}_BRL_price"] = 'abc';
    $probs = E::problems($bad);
    ok(isset($probs["p{$plan->id}_EUR_setup"], $probs["p{$plan->id}_BRL_price"]), 'problems: setup without price, not a number');

    $dirty = array_diff_assoc($c, $cells);
    $r = E::save($dirty, null);
    ok($r['changes'] === 4 && !$r['problems'], 'saved 4 changes (' . implode(', ', array_keys($dirty)) . ')');
    $usd = Price::where('plan_id', $plan->id)->where('currency_code', 'USD')->first();
    ok((float) Price::where('plan_id', $plan->id)->where('currency_code', 'BRL')->value('price') === 109.9 && $usd && (float) $usd->price === 21.99 && (int) Product::find($product->id)->stock === 15, 'database: BRL 109.90, new USD 21.99, stock 15');
    ok(E::save($dirty, null)['changes'] === 0, 'saving the same again changes nothing');
    $last = E::lastBatch();
    ok($last && $last['batch'] === $r['batch'] && $last['changes'] === 4, 'last save listed for undo');
    $r2 = E::save(["p{$plan->id}_USD_price" => ''], null);
    ok($r2['changes'] === 1 && !Price::where('plan_id', $plan->id)->where('currency_code', 'USD')->exists(), 'empty price → removed (not sold in USD)');
    E::undo($r2['batch']);
    ok((float) Price::where('plan_id', $plan->id)->where('currency_code', 'USD')->value('price') === 21.99, 'undo puts the USD price back');
    E::undo($r['batch']);
    ok((float) Price::where('plan_id', $plan->id)->where('currency_code', 'BRL')->value('price') === 100.0 && !Price::where('plan_id', $plan->id)->where('currency_code', 'USD')->exists()
        && (float) Price::where('plan_id', $yearly->id)->where('currency_code', 'BRL')->value('price') === 1100.0 && (int) Product::find($product->id)->stock === 5, 'undo of the first save: BRL 100, no USD, yearly 1100, stock 5');
    ok(E::products(['search' => $product->name])->contains('id', $product->id) && E::products(['stock' => 'low'], 5)->contains('id', $product->id) && !E::products(['stock' => 'low'], 3)->contains('id', $product->id), 'filters: search, low stock');
    ok(E::products(['missing' => 'USD'])->contains('id', $product->id) && ($yp = E::products(['period' => '12-month'])->first(fn ($p) => $p->id === $product->id)?->plans->pluck('id')) && $yp->contains($yearly->id) && !$yp->contains($plan->id), 'filters: missing USD, yearly plans only');

    echo "Low stock e-mail\n";
    config(['mail.default' => 'array']);
    Mail::purge();
    $sent = fn () => count(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    $set(['low_stock_email' => 'ops@example.com', 'low_stock' => '3']);
    $pr = Product::find($product->id);
    $pr->stock = 4; $pr->save(); ok($sent() === 0, '5 → 4: no e-mail');
    $pr->stock = 3; $pr->save(); ok($sent() === 1, '4 → 3: low stock e-mail');
    $pr->stock = 2; $pr->save(); ok($sent() === 1, '3 → 2: not again');
    $pr->stock = 0; $pr->save(); ok($sent() === 2, '→ 0: out of stock e-mail');
    $msg = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    ok(str_starts_with($msg->getSubject(), 'Out of stock: ') && str_contains($msg->getTextBody(), '/admin/pricing-prices'), 'e-mail: "' . $msg->getSubject() . '" with a link to the page');
    $set(['low_stock_email' => '']);

    echo "Promotions\n";
    $other = Product::where('id', '!=', $product->id)->first();
    $promo = P::save(['name' => 'Black Friday', 'code' => '', 'kind' => 'price', 'percent' => '20', 'cycles' => '3', 'starts_at' => now('America/Sao_Paulo')->subHour()->format('Y-m-d\TH:i'),
        'expires_at' => now('America/Sao_Paulo')->addDays(3)->format('Y-m-d\TH:i'), 'max_uses' => '2', 'per_user' => '1', 'auto_apply' => true, 'show_badge' => true, 'products' => [(string) $product->id]]);
    $cp = $promo->coupon;
    ok($cp->code === 'BLACKFRIDAY' && $cp->type === 'percentage' && (float) $cp->value === 20.0 && (int) $cp->recurring === 3 && (int) $cp->max_uses === 2 && $cp->products->pluck('id')->all() === [$product->id], 'coupon BLACKFRIDAY: 20 %, 3 payments, 2 orders, 1 product');
    ok(P::status($cp) === 'active' && P::forProduct($product->id)['coupon_id'] === $cp->id && P::forProduct($other->id) === null, 'running; only for its product');
    $b = P::badge($product->id, 100);
    ok($b['label'] === '−20%' && $b['discounted'] === 80.0 && $b['left'] !== null && str_contains((string) $b['ends'], '3'), 'badge: −20%, 100 → 80, "' . $b['left'] . '", "' . $b['ends'] . '"');
    $promo2 = P::save(['name' => 'Black Friday', 'code' => '', 'kind' => 'free_setup', 'percent' => '', 'cycles' => '1', 'starts_at' => '', 'expires_at' => '', 'max_uses' => '', 'per_user' => '', 'auto_apply' => true, 'show_badge' => true, 'products' => []]);
    ok(str_starts_with($promo2->coupon->code, 'BLACKFRIDAY') && $promo2->coupon->code !== 'BLACKFRIDAY' && $promo2->coupon->products->isEmpty(), 'same name → unique code (' . $promo2->coupon->code . '), all products');
    ok(P::forProduct($product->id)['coupon_id'] === $cp->id && P::forProduct($other->id)['coupon_id'] === $promo2->coupon->id, '20 % beats free setup; free setup for the others');
    try { P::save(['name' => 'X', 'code' => 'BLACKFRIDAY', 'kind' => 'price', 'percent' => '5', 'cycles' => '1', 'products' => []]); ok(false, 'taken code refused'); }
    catch (\InvalidArgumentException $e) { ok(true, 'taken code refused: ' . $e->getMessage()); }

    echo "Cart\n";
    $cartModel = \App\Models\Cart::create(['currency_code' => 'BRL']);
    \App\Models\CartItem::create(['cart_id' => $cartModel->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
    $visit = function () use ($cartModel) {
        app()->instance('request', \Illuminate\Http\Request::create('/cart', 'GET', [], ['cart' => $cartModel->ulid]));
        \Illuminate\Support\Facades\Cookie::clearResolvedInstances();
        \Illuminate\Support\Once::flush();
        P::applyToCart();

        return \App\Models\Cart::find($cartModel->id)->coupon_id;
    };
    ok($visit() === $cp->id, 'cart gets BLACKFRIDAY automatically');
    $cartModel->refresh()->update(['coupon_id' => $promo2->coupon->id]);
    ok($visit() === $promo2->coupon->id, 'a coupon already on the cart is not replaced');
    $cartModel->update(['coupon_id' => null]);
    $set(['auto_apply' => '0']);
    ok($visit() === null, 'switched off in the settings → not applied');
    $set(['auto_apply' => '1']);
    $promo->update(['auto_apply' => false]); P::flush();
    ok($visit() === $promo2->coupon->id, '"code only" promotion skipped → the free setup one is used');
    $promo->update(['auto_apply' => true]); $cartModel->update(['coupon_id' => null]); P::flush();

    // two orders use it → sold out
    $user = \App\Models\User::first();
    foreach ([1, 2] as $i) {
        DB::table('services')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 80, 'quantity' => 1, 'currency_code' => 'BRL', 'status' => 'active', 'coupon_id' => $cp->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    P::flush();
    ok(P::status($cp->fresh()) === 'sold_out' && P::forProduct($product->id)['coupon_id'] !== $cp->id, 'after 2 orders: sold out, badge gone');
    ok($visit() === $promo2->coupon->id, 'sold out → the next promotion is used');
    ok(P::delete($promo->fresh('coupon')) === 'ended' && \App\Models\Coupon::find($cp->id) !== null, 'delete of a used promotion only ends it (coupon kept for renewals)');
    ok(P::delete($promo2->fresh('coupon')) === 'deleted' && \App\Models\Coupon::find($promo2->coupon_id) === null, 'unused promotion really deleted');
} catch (\Throwable $e) {
    ok(false, 'exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    DB::rollBack();
    P::flush();
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
