<?php

namespace Paymenter\Extensions\Others\Pricing;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Models\Extension as ExtensionModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;

#[ExtensionMeta(
    name: 'qsync Pricing',
    description: 'One place to edit prices (BRL, USD…) and stock of many products at once, and to run promotions: a % off for a limited time or for a limited number of orders, applied automatically in the cart.',
    version: '1.0.1',
    author: 'qsync networks',
    url: 'https://github.com/sagattiger/paymenter-pricing',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+PHJlY3Qgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0IiByeD0iMTQiIGZpbGw9IiMwZjE3MmEiLz48cGF0aCBkPSJNMTQgMzRsMTgtMThoMThWMzRMMzIgNTJ6IiBmaWxsPSJub25lIiBzdHJva2U9IiMyMmQzZWUiIHN0cm9rZS13aWR0aD0iNSIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIvPjxjaXJjbGUgY3g9IjQyIiBjeT0iMjQiIHI9IjQiIGZpbGw9IiMyMmQzZWUiLz48L3N2Zz4=',
)]
class Pricing extends Extension
{
    public const EXT = 'Pricing';

    public const SCHEMA = 1;

    protected static ?self $inst = null;

    public static function instance(): self
    {
        if (static::$inst) {
            return static::$inst;
        }
        $rec = ExtensionModel::where('extension', self::EXT)->first();

        return static::$inst = new self($rec ? $rec->settings->pluck('value', 'key')->toArray() : []);
    }

    public function getConfig($values = [])
    {
        return [
            ['name' => 'about', 'type' => 'placeholder', 'label' => new HtmlString('Pages: <b>Pricing → Prices & stock</b> and <b>Pricing → Promotions</b> in the admin menu.')],
            ['name' => 'auto_apply', 'label' => 'Apply promotions automatically in the cart (no code to type)', 'type' => 'checkbox', 'default' => true,
                'description' => 'Per promotion you can still choose "only with the code". A code the customer typed is never replaced.'],
            ['name' => 'badges', 'label' => 'Show sale badges in the Marketplace (−20%, old price, ends …, only N left)', 'type' => 'checkbox', 'default' => true],
            ['name' => 'timezone', 'label' => 'Time zone for promotion dates', 'type' => 'text', 'default' => 'America/Sao_Paulo'],
            ['name' => 'low_stock', 'label' => 'Low stock warning at', 'type' => 'text', 'default' => '3',
                'description' => 'Products with this many units or fewer are marked on the Prices & stock page, and an e-mail is sent when one gets there.'],
            ['name' => 'low_stock_email', 'label' => 'Send the low stock e-mail to', 'type' => 'text', 'default' => '', 'placeholder' => 'you@qsync.net (empty = no e-mail)'],
        ];
    }

    public function str(string $k, string $d = ''): string
    {
        $v = $this->config($k);

        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : $d;
    }

    public function bool(string $k, bool $d): bool
    {
        $v = $this->config($k);
        if ($v === null || $v === '') {
            return $d;
        }

        return is_bool($v) ? $v : in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Switched on in Admin → Extensions (other extensions ask before showing sale badges). */
    public static function isEnabled(): bool
    {
        try {
            return (bool) Cache::remember('pricing_ext:enabled', 60, fn () => (int) ExtensionModel::where('extension', self::EXT)->where('enabled', true)->exists());
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function updated()
    {
        Cache::forget('pricing_ext:enabled');
        Services\Promotions::flush();
    }

    public function enabled($extension = null)
    {
        Cache::forget('pricing_ext:enabled');
    }

    public function disabled($extension = null)
    {
        Cache::forget('pricing_ext:enabled');
    }

    public function lowStock(): int
    {
        return max(0, (int) $this->str('low_stock', '3'));
    }

    public function tz(): string
    {
        $tz = $this->str('timezone', 'America/Sao_Paulo');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'America/Sao_Paulo';
    }

    public function boot()
    {
        static::$inst = $this;
        $this->ensureSchema();
        View::addNamespace('pricing', __DIR__ . '/resources/views');
        Event::listen('permissions', fn () => ['admin.pricing.manage' => 'Edit prices, stock and promotions (qsync Pricing)']);

        app('router')->pushMiddlewareToGroup('web', Http\ApplyPromotion::class);

        // Low stock e-mail when a product reaches the warning level (checkout lowers stock with a normal save).
        \App\Models\Product::updated(function (\App\Models\Product $p) {
            if ($p->wasChanged('stock')) {
                Services\Stock::checkLow($p, $p->getOriginal('stock'));
            }
        });
        foreach ([\App\Models\Coupon::class, \App\Models\Service::class] as $m) {
            $m::saved(fn () => Services\Promotions::flush());
            $m::deleted(fn () => Services\Promotions::flush());
        }
    }

    public function ensureSchema(): void
    {
        try {
            if (Cache::get('pricing_ext_schema') === self::SCHEMA) {
                return;
            }
            if (!Schema::hasTable('ext_pricing_changes')) {
                Schema::create('ext_pricing_changes', function (Blueprint $t) {
                    $t->id();
                    $t->string('batch', 36)->index();
                    $t->unsignedBigInteger('user_id')->nullable();
                    $t->unsignedBigInteger('product_id')->nullable();
                    $t->unsignedBigInteger('plan_id')->nullable();
                    $t->string('currency', 3)->nullable();
                    $t->string('field', 16);                  // price | setup_fee | stock
                    $t->decimal('old_value', 17, 2)->nullable(); // null = no price / unlimited
                    $t->decimal('new_value', 17, 2)->nullable();
                    $t->timestamp('undone_at')->nullable();
                    $t->timestamps();
                });
            }
            if (!Schema::hasTable('ext_pricing_promotions')) {
                Schema::create('ext_pricing_promotions', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('coupon_id')->unique();
                    $t->string('name');
                    $t->boolean('auto_apply')->default(true);
                    $t->boolean('show_badge')->default(true);
                    $t->string('note')->nullable();
                    $t->timestamps();
                });
            }
            Cache::forever('pricing_ext_schema', self::SCHEMA);
        } catch (\Throwable $e) {
            Log::warning('Pricing: schema not ready: ' . $e->getMessage());
        }
    }
}
