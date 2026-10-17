<?php

namespace Paymenter\Extensions\Others\Pricing\Admin\Pages;

use App\Models\Category;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Paymenter\Extensions\Others\Pricing\Pricing;
use Paymenter\Extensions\Others\Pricing\Services\PriceEditor;
use Paymenter\Extensions\Others\Pricing\Services\Promotions;
use Paymenter\Extensions\Others\Pricing\Support\Math;

/** Pricing → Prices & stock: every product's prices (all currencies) and stock on one page, with bulk tools. */
class Prices extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Prices & stock';

    protected static ?string $title = 'Prices & stock';

    protected static ?string $slug = 'pricing-prices';

    protected static ?int $navigationSort = 1;

    protected string $view = 'pricing::admin.prices';

    // filters
    public string $search = '';

    public ?int $category = null;

    public string $family = '';

    public string $period = '';

    public string $visibility = '';

    public string $stockFilter = '';

    public string $missing = '';

    public array $shown = [];

    public int $page = 1;

    // values: cells = what is shown (stored values + edits), dirty = edited cells only (kept across filters)
    public array $cells = [];

    public array $dirty = [];

    public array $problems = [];

    public array $selPlans = [];

    public array $selProducts = [];

    // bulk tool
    public string $target = 'price';

    public string $currency = '';

    public string $op = 'percent';

    public string $value = '';

    public string $round = '90';

    public string $source = '';

    public const PER_PAGE = 40;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && method_exists($u, 'hasPermission') && $u->hasPermission('admin.pricing.manage');
    }

    public function mount(): void
    {
        $this->search = (string) request()->query('search', '');
        $cur = PriceEditor::currencies();
        $this->shown = $cur;
        $this->currency = $cur[0] ?? '';
        $this->source = $cur[0] ?? '';
        $this->refreshCells();
    }

    public function getFilters(): array
    {
        return ['search' => $this->search, 'category' => $this->category, 'family' => $this->family, 'period' => $this->period,
            'hidden' => $this->visibility, 'stock' => $this->stockFilter, 'missing' => $this->missing];
    }

    protected $productsCache = null;

    public function getAllProducts()
    {
        return $this->productsCache ??= PriceEditor::products($this->getFilters(), Pricing::instance()->lowStock());
    }

    public function getPageProducts()
    {
        return $this->getAllProducts()->forPage($this->page, self::PER_PAGE)->values();
    }

    public function updated($name): void
    {
        if (in_array($name, ['search', 'category', 'family', 'period', 'visibility', 'stockFilter', 'missing'], true)) {
            $this->page = 1;
            $this->productsCache = null;
            $this->selPlans = $this->selProducts = [];
            $this->refreshCells();
        }
    }

    public function updatedCells($value, $key): void
    {
        $stored = PriceEditor::snapshot(PriceEditor::load(...PriceEditor::ids([$key])), PriceEditor::currencies());
        if (!array_key_exists($key, $stored)) {
            return;
        }
        $norm = fn ($v) => str_starts_with($key, 's') ? trim((string) $v) : (($n = Math::parse($v)) === null || $n === false ? trim((string) $v) : PriceEditor::fmt($n));
        if ($norm($value) === $norm($stored[$key])) {
            unset($this->dirty[$key]);
        } else {
            $this->dirty[$key] = trim((string) $value);
        }
        unset($this->problems[$key]);
    }

    public function goPage(int $p): void
    {
        $this->page = max(1, $p);
        $this->refreshCells();
    }

    /** Stored values of the rows on this page, with the edits on top. */
    public function refreshCells(): void
    {
        $this->productsCache = null;
        $this->cells = array_merge(PriceEditor::snapshot($this->getPageProducts(), PriceEditor::currencies()), $this->dirty);
    }

    public function selectShown(bool $on = true): void
    {
        $products = $this->getPageProducts();
        $this->selProducts = $on ? $products->pluck('id')->map(fn ($v) => (string) $v)->all() : [];
        $this->selPlans = $on ? $products->flatMap(fn ($p) => $p->plans->pluck('id'))->map(fn ($v) => (string) $v)->all() : [];
    }

    public function toggleProduct(int $id): void
    {
        $product = $this->getPageProducts()->firstWhere('id', $id);
        if (!$product) {
            return;
        }
        $planIds = $product->plans->pluck('id')->map(fn ($v) => (string) $v)->all();
        if (in_array((string) $id, $this->selProducts, true)) {
            $this->selProducts = array_values(array_diff($this->selProducts, [(string) $id]));
            $this->selPlans = array_values(array_diff($this->selPlans, $planIds));
        } else {
            $this->selProducts[] = (string) $id;
            $this->selPlans = array_values(array_unique(array_merge($this->selPlans, $planIds)));
        }
    }

    public function applyBulk(): void
    {
        if ($this->target === 'stock' ? !$this->selProducts : !$this->selPlans) {
            Notification::make()->title('Select products first (tick the boxes on the left)')->warning()->send();

            return;
        }
        if ($this->op !== 'clear' && $this->op !== 'unlimited' && Math::parse($this->value) === false) {
            Notification::make()->title('"' . $this->value . '" is not a number')->danger()->send();

            return;
        }
        if ($this->target !== 'stock' && $this->op === 'convert' && $this->source === $this->currency) {
            Notification::make()->title('Pick another currency to convert from')->warning()->send();

            return;
        }
        $products = PriceEditor::load($this->selProducts, $this->selPlans);
        $base = array_merge(PriceEditor::snapshot($products, PriceEditor::currencies()), $this->dirty);
        $after = PriceEditor::bulk($base, $products, array_map('intval', $this->selPlans), array_map('intval', $this->selProducts), [
            'target' => $this->target, 'currency' => $this->currency, 'op' => $this->op, 'value' => $this->value, 'round' => $this->round, 'source' => $this->source,
        ]);
        $n = 0;
        foreach ($after as $k => $v) {
            if (($base[$k] ?? null) !== $v) {
                $this->updatedCells($v, $k);
                $n++;
            }
        }
        $this->refreshCells();
        Notification::make()->title($n . ' value(s) changed — check them, then press Save')->success()->send();
    }

    public function discard(): void
    {
        $this->dirty = $this->problems = [];
        $this->refreshCells();
    }

    public function save(): void
    {
        if (!$this->dirty) {
            Notification::make()->title('Nothing to save')->send();

            return;
        }
        $r = PriceEditor::save($this->dirty, auth()->id());
        if ($r['problems']) {
            $this->problems = $r['problems'];
            Notification::make()->title('Fix the cells marked in red (' . count($r['problems']) . ')')->danger()->send();

            return;
        }
        $this->dirty = $this->problems = [];
        $this->refreshCells();
        Notification::make()->title($r['changes'] . ' change(s) saved')->body('Existing customers keep the price of their current service; new orders use the new prices.')->success()->send();
    }

    public function undoLast(): void
    {
        $last = PriceEditor::lastBatch();
        if (!$last) {
            return;
        }
        $n = PriceEditor::undo($last['batch']);
        $this->dirty = [];
        $this->refreshCells();
        Notification::make()->title('Undone: ' . $n . ' value(s) put back')->success()->send();
    }

    public function getCategories(): array
    {
        return Category::orderBy('name')->pluck('name', 'id')->all();
    }

    public function getFamilies(): array
    {
        if (!class_exists('\\Paymenter\\Extensions\\Others\\Marketplace\\Models\\Item')) {
            return [];
        }

        return \Paymenter\Extensions\Others\Marketplace\Models\Item::whereNotNull('plan_group')->where('plan_group', '!=', '')->distinct()->orderBy('plan_group')->pluck('plan_group')->all();
    }

    public function getPeriods(): array
    {
        return ['1-month' => 'Monthly', '3-month' => 'Quarterly', '6-month' => 'Every 6 months', '12-month' => 'Yearly', '24-month' => 'Every 2 years', 'one-time' => 'One-time'];
    }

    public function promoFor(int $productId): ?array
    {
        return Promotions::forProduct($productId, false);
    }
}
