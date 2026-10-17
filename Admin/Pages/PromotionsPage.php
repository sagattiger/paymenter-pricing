<?php

namespace Paymenter\Extensions\Others\Pricing\Admin\Pages;

use App\Models\Product;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Paymenter\Extensions\Others\Pricing\Models\Promotion;
use Paymenter\Extensions\Others\Pricing\Pricing;
use Paymenter\Extensions\Others\Pricing\Services\Promotions;

/** Pricing → Promotions: % off for a limited time and/or a limited number of orders. */
class PromotionsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Promotions';

    protected static ?string $title = 'Promotions';

    protected static ?string $slug = 'pricing-promotions';

    protected static ?int $navigationSort = 2;

    protected string $view = 'pricing::admin.promotions';

    public ?int $editing = null;   // promotion id, 0 = new

    public array $form = [];

    public string $productSearch = '';

    public string $pickFamily = '';

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'hasPermission') && $u->hasPermission('admin.pricing.manage');
    }

    public function mount(): void
    {
        $ids = array_filter(array_map('intval', explode(',', (string) request()->query('products', ''))));
        if ($ids) {
            $this->create();
            $this->form['products'] = array_map('strval', $ids);
        }
    }

    public function blank(): array
    {
        $tz = Pricing::instance()->tz();

        return [
            'name' => '', 'code' => '', 'kind' => 'price', 'percent' => '20', 'cycles' => '1',
            'starts_at' => now($tz)->format('Y-m-d\TH:i'), 'expires_at' => now($tz)->addDays(7)->setTime(23, 59)->format('Y-m-d\TH:i'),
            'max_uses' => '', 'per_user' => '1', 'auto_apply' => true, 'show_badge' => true, 'all_products' => false, 'products' => [], 'note' => '',
        ];
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->form = $this->blank();
    }

    public function edit(int $id): void
    {
        $p = Promotion::with('coupon.products')->find($id);
        if (!$p || !$p->coupon) {
            return;
        }
        $c = $p->coupon;
        $tz = Pricing::instance()->tz();
        $this->editing = $p->id;
        $this->form = [
            'name' => $p->name, 'code' => $c->code,
            'kind' => $c->type === 'free_setup' ? 'free_setup' : ($c->applies_to === 'all' ? 'all' : 'price'),
            'percent' => (string) (float) $c->value, 'cycles' => (string) (int) ($c->recurring ?? 1),
            'starts_at' => $c->starts_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i') ?? '',
            'expires_at' => $c->expires_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i') ?? '',
            'max_uses' => (string) ($c->max_uses ?? ''), 'per_user' => (string) ($c->max_uses_per_user ?? ''),
            'auto_apply' => $p->auto_apply, 'show_badge' => $p->show_badge,
            'all_products' => $c->products->isEmpty(), 'products' => $c->products->pluck('id')->map(fn ($v) => (string) $v)->all(), 'note' => (string) $p->note,
        ];
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->form = [];
    }

    public function savePromotion(): void
    {
        $this->validate([
            'form.name' => 'required|string|max:80',
            'form.code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]*$/'],
            'form.kind' => 'required|in:price,all,free_setup',
            'form.percent' => 'required_unless:form.kind,free_setup|nullable|numeric|min:1|max:100',
            'form.cycles' => 'required|integer|min:0|max:120',
            'form.starts_at' => 'nullable|date',
            'form.expires_at' => 'nullable|date',
            'form.max_uses' => 'nullable|integer|min:1',
            'form.per_user' => 'nullable|integer|min:1',
        ], [], ['form.name' => 'name', 'form.percent' => 'discount', 'form.max_uses' => 'number of orders', 'form.expires_at' => 'end']);
        $f = $this->form;
        if (!empty($f['starts_at']) && !empty($f['expires_at']) && Carbon::parse($f['expires_at'])->lte(Carbon::parse($f['starts_at']))) {
            $this->addError('form.expires_at', 'The end must be after the start.');

            return;
        }
        if (empty($f['all_products']) && empty($f['products'])) {
            $this->addError('form.products', 'Choose the products, or tick "All products".');

            return;
        }
        if (!empty($f['all_products'])) {
            $f['products'] = [];
        }
        try {
            $p = Promotions::save($f, $this->editing ? Promotion::find($this->editing) : null);
        } catch (\Throwable $e) {
            Notification::make()->title('Not saved')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('"' . $p->name . '" saved')->body('Code ' . $p->coupon->code . ($p->auto_apply ? ' — applied automatically in the cart' : ''))->success()->send();
        $this->cancel();
    }

    public function endNow(int $id): void
    {
        if ($p = Promotion::with('coupon')->find($id)) {
            Promotions::end($p);
            Notification::make()->title('"' . $p->name . '" ended')->success()->send();
        }
    }

    public function remove(int $id): void
    {
        if ($p = Promotion::with('coupon')->find($id)) {
            $r = Promotions::delete($p);
            Notification::make()->title($r === 'deleted' ? 'Deleted' : 'Ended (it was used, so it is kept for those customers\' renewals)')->success()->send();
        }
    }

    public function getRows(): array
    {
        $tz = Pricing::instance()->tz();
        $rows = [];
        foreach (Promotions::all() as $p) {
            $c = $p->coupon;
            $rows[] = [
                'id' => $p->id, 'name' => $p->name, 'code' => $c->code,
                'discount' => $c->type === 'free_setup' ? 'Free setup fee' : (float) $c->value . '% off' . ($c->applies_to === 'all' ? ' (price + setup)' : ''),
                'cycles' => (int) ($c->recurring ?? 1) === 0 ? 'every payment' : ((int) ($c->recurring ?? 1) <= 1 ? 'first payment' : 'first ' . (int) $c->recurring . ' payments'),
                'window' => ($c->starts_at ? $c->starts_at->copy()->setTimezone($tz)->format('d/m H:i') : 'now') . ' → ' . ($c->expires_at ? $c->expires_at->copy()->setTimezone($tz)->format('d/m H:i') : 'no end'),
                'used' => Promotions::used($c), 'max' => $c->max_uses,
                'products' => $c->products->isEmpty() ? 'All products' : $c->products->count() . ' product(s)',
                'status' => Promotions::status($c), 'auto' => $p->auto_apply,
            ];
        }

        return $rows;
    }

    public function getPickable(): array
    {
        $q = Product::query()->with('category')->orderBy('category_id')->orderBy('name');
        if (trim($this->productSearch) !== '') {
            $q->where('name', 'like', '%' . trim($this->productSearch) . '%');
        }

        return $q->limit(300)->get()->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name, 'cat' => $p->category?->name])->all();
    }

    public function getFamilies(): array
    {
        if (!class_exists('\\Paymenter\\Extensions\\Others\\Marketplace\\Models\\Item')) {
            return [];
        }

        return \Paymenter\Extensions\Others\Marketplace\Models\Item::whereNotNull('plan_group')->where('plan_group', '!=', '')->distinct()->orderBy('plan_group')->pluck('plan_group')->all();
    }

    public function updatedPickFamily($family): void
    {
        if ($family !== '' && class_exists('\\Paymenter\\Extensions\\Others\\Marketplace\\Models\\Item')) {
            $ids = \Paymenter\Extensions\Others\Marketplace\Models\Item::where('plan_group', $family)->pluck('product_id')->map(fn ($v) => (string) $v)->all();
            $this->form['products'] = array_values(array_unique(array_merge($this->form['products'] ?? [], $ids)));
        }
        $this->pickFamily = '';
    }

    public function pickShown(bool $on): void
    {
        $ids = array_column($this->getPickable(), 'id');
        $this->form['products'] = $on ? array_values(array_unique(array_merge($this->form['products'] ?? [], $ids))) : array_values(array_diff($this->form['products'] ?? [], $ids));
    }
}
