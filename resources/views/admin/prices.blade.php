<x-filament-panels::page>
    @php
        $currencies = \Paymenter\Extensions\Others\Pricing\Services\PriceEditor::currencies();
        $all = $this->getAllProducts();
        $products = $this->getPageProducts();
        $pages = max(1, (int) ceil($all->count() / \Paymenter\Extensions\Others\Pricing\Admin\Pages\Prices::PER_PAGE));
        $low = \Paymenter\Extensions\Others\Pricing\Pricing::instance()->lowStock();
        $last = \Paymenter\Extensions\Others\Pricing\Services\PriceEditor::lastBatch();
        $cols = array_values(array_intersect($currencies, $shown));
        $families = $this->getFamilies();
    @endphp
    <style>
        .prc{display:grid;gap:14px}
        .prc-box{border:1px solid rgba(127,127,127,.25);border-radius:14px;padding:14px 16px;background:rgba(127,127,127,.04)}
        .prc-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
        .prc label{display:flex;flex-direction:column;gap:4px;font-size:.78rem;font-weight:600}
        .prc input[type=text],.prc input[type=search],.prc select{padding:6px 9px;border-radius:8px;border:1px solid rgba(127,127,127,.35);background:transparent;color:inherit;font:inherit;font-weight:400;font-size:.86rem}
        .prc select option{color:#111}
        .prc-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:9px;font-weight:600;font-size:.85rem;border:1px solid rgba(127,127,127,.35);white-space:nowrap}
        .prc-btn.primary{background:var(--primary-600,#4f46e5);color:#fff;border-color:transparent}
        .prc-btn.danger{color:#dc2626}
        .prc-hint{font-size:.78rem;opacity:.7}
        .prc-table{width:100%;border-collapse:collapse;font-size:.84rem}
        .prc-table th{position:sticky;top:0;background:rgb(var(--gray-50,249 250 251));font-weight:600;text-align:left;padding:6px;border-bottom:1px solid rgba(127,127,127,.3);z-index:1}
        .dark .prc-table th{background:rgb(24 24 27)}
        .prc-table td{padding:4px 6px;border-bottom:1px solid rgba(127,127,127,.12);vertical-align:middle}
        .prc-table tr.first td{border-top:1px solid rgba(127,127,127,.35)}
        .prc-cell{display:flex;flex-direction:column;gap:2px;min-width:92px}
        .prc-cell input{width:100%;padding:4px 6px!important;font-variant-numeric:tabular-nums;text-align:right}
        .prc-cell input.setup{font-size:.72rem!important;opacity:.8}
        .prc-cell input.dirty{background:rgba(234,179,8,.18)!important;border-color:#ca8a04!important}
        .prc-cell input.bad{background:rgba(220,38,38,.12)!important;border-color:#dc2626!important}
        .prc-stock{width:70px!important;text-align:right}
        .prc-pill{display:inline-block;padding:1px 7px;border-radius:999px;font-size:.7rem;font-weight:600}
        .prc-pill.low{background:rgba(220,38,38,.14);color:#dc2626}
        .prc-pill.promo{background:rgba(22,163,74,.14);color:#16a34a}
        .prc-pill.hid{background:rgba(127,127,127,.15)}
        .prc-bar{position:sticky;bottom:10px;z-index:5;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 14px;border-radius:12px;background:rgb(254 249 195);color:#713f12;box-shadow:0 6px 20px rgba(0,0,0,.18)}
    </style>

    <div class="prc">
        {{-- Filters --}}
        <div class="prc-box prc-row">
            <label style="flex:1;min-width:180px">Search<input type="search" wire:model.live.debounce.400ms="search" placeholder="Product name"></label>
            <label>Category<select wire:model.live="category"><option value="">All</option>@foreach ($this->getCategories() as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></label>
            @if ($families)
                <label>Marketplace family<select wire:model.live="family"><option value="">All</option>@foreach ($families as $f)<option value="{{ $f }}">{{ $f }}</option>@endforeach</select></label>
            @endif
            <label>Billing period<select wire:model.live="period"><option value="">All</option>@foreach ($this->getPeriods() as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select></label>
            <label>Shown in store<select wire:model.live="visibility"><option value="">All</option><option value="visible">Visible</option><option value="hidden">Hidden</option></select></label>
            <label>Stock<select wire:model.live="stockFilter"><option value="">All</option><option value="limited">Limited stock</option><option value="low">Low stock (≤ {{ $low }})</option></select></label>
            <label>Missing a price in<select wire:model.live="missing"><option value="">—</option>@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
            <label>Columns
                <span style="display:flex;gap:8px;font-weight:400;padding:6px 0">@foreach ($currencies as $c)<span><input type="checkbox" wire:model.live="shown" value="{{ $c }}"> {{ $c }}</span>@endforeach</span>
            </label>
        </div>

        {{-- Bulk tool --}}
        <div class="prc-box">
            <div class="prc-row">
                <b style="font-size:.9rem;align-self:center">Change the {{ count($selPlans) }} selected plan(s) / {{ count($selProducts) }} product(s):</b>
                <label>What<select wire:model.live="target"><option value="price">Price</option><option value="setup">Setup fee</option><option value="stock">Stock</option></select></label>
                @if ($target === 'stock')
                    <label>How<select wire:model.live="op"><option value="add">Add units</option><option value="set">Set units to</option><option value="unlimited">Unlimited</option></select></label>
                    @if ($op !== 'unlimited')<label>Units<input type="text" wire:model="value" style="width:80px" placeholder="10"></label>@endif
                @else
                    <label>Currency<select wire:model.live="currency">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
                    <label>How<select wire:model.live="op">
                        <option value="percent">Raise / lower by %</option>
                        <option value="amount">Raise / lower by an amount</option>
                        <option value="set">Set to</option>
                        <option value="convert">From another currency × rate</option>
                        <option value="months">Longer plans = monthly × months − %</option>
                        <option value="clear">Remove (not sold in this currency)</option>
                    </select></label>
                    @if ($op === 'convert')
                        <label>From<select wire:model="source">@foreach ($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></label>
                    @endif
                    @if ($op !== 'clear')
                        <label>{{ ['percent' => '% (−15 = 15 % off)', 'amount' => 'Amount (−5 or 5)', 'set' => 'New value', 'convert' => 'Rate (e.g. 0.20)', 'months' => 'Discount %'][$op] ?? 'Value' }}
                            <input type="text" wire:model="value" style="width:110px" placeholder="{{ ['percent' => '10', 'amount' => '-5', 'set' => '29,90', 'convert' => '0.20', 'months' => '15'][$op] ?? '' }}"></label>
                        <label>Round<select wire:model="round">@foreach (\Paymenter\Extensions\Others\Pricing\Support\Math::ROUNDING as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select></label>
                    @endif
                @endif
                <button type="button" class="prc-btn" wire:click="applyBulk">Apply to selected</button>
                @if ($selProducts)
                    <a class="prc-btn" href="{{ url('/admin/pricing-promotions') }}?products={{ implode(',', $selProducts) }}">Create a promotion…</a>
                @endif
            </div>
            <p class="prc-hint" style="margin:8px 0 0">
                Tools only fill in the table (yellow cells); nothing is stored until you press <b>Save</b>.
                @if ($op === 'months' && $target !== 'stock') Example: monthly 30, discount 15 % → yearly = 30 × 12 − 15 % = 306. @endif
                @if ($op === 'convert' && $target !== 'stock') Example: {{ $currency }} = {{ $source }} × 0.20, then rounded. @endif
            </p>
        </div>

        @if ($last)
            <div class="prc-hint" style="display:flex;gap:10px;align-items:center">
                Last save: {{ \Carbon\Carbon::parse($last['at'])->setTimezone(\Paymenter\Extensions\Others\Pricing\Pricing::instance()->tz())->format('d/m H:i') }} — {{ $last['changes'] }} value(s){{ $last['user'] ? ' by ' . $last['user'] : '' }}
                <button type="button" class="prc-btn" style="padding:3px 10px" wire:click="undoLast" wire:confirm="Put back the values from before that save?">Undo it</button>
            </div>
        @endif

        {{-- Table --}}
        <div class="prc-box" style="padding:0;overflow:auto;max-height:70vh">
            <table class="prc-table">
                <thead><tr>
                    <th style="width:30px"><input type="checkbox" wire:click="selectShown($event.target.checked)" title="Select all on this page"></th>
                    <th>Product</th><th>Stock</th><th>Plan</th>
                    @foreach ($cols as $c)<th style="text-align:right">{{ $c }} <span class="prc-hint" style="font-weight:400">price / setup</span></th>@endforeach
                </tr></thead>
                <tbody>
                @forelse ($products as $p)
                    @php $promo = $this->promoFor($p->id); $plans = $p->plans; $sk = 's' . $p->id; @endphp
                    @foreach ($plans->isEmpty() ? [null] : $plans as $i => $plan)
                        <tr class="{{ $i === 0 ? 'first' : '' }}" wire:key="r-{{ $p->id }}-{{ $plan?->id ?? 0 }}">
                            <td>@if ($plan)<input type="checkbox" wire:model.live="selPlans" value="{{ $plan->id }}">@endif</td>
                            @if ($i === 0)
                                <td rowspan="{{ max(1, $plans->count()) }}" style="min-width:200px;vertical-align:top;padding-top:8px">
                                    <label style="flex-direction:row;align-items:center;gap:6px;font-weight:600;font-size:.84rem">
                                        <input type="checkbox" @checked(in_array((string) $p->id, $selProducts, true)) wire:click="toggleProduct({{ $p->id }})">
                                        <a href="{{ url('/admin/products/' . $p->id . '/edit') }}" target="_blank" style="text-decoration:none">{{ $p->name }}</a>
                                    </label>
                                    <span class="prc-hint">{{ $p->category?->name }}</span>
                                    @if ($p->hidden)<span class="prc-pill hid">hidden</span>@endif
                                    @if ($p->stock !== null && $p->stock <= $low)<span class="prc-pill low">{{ $p->stock === 0 ? 'out of stock' : 'low stock' }}</span>@endif
                                    @if ($promo)<span class="prc-pill promo" title="{{ $promo['name'] }}">{{ $promo['type'] === 'free_setup' ? 'free setup' : '−' . $promo['percent'] . '%' }}</span>@endif
                                </td>
                                <td rowspan="{{ max(1, $plans->count()) }}" style="vertical-align:top;padding-top:8px">
                                    <div class="prc-cell" style="min-width:0">
                                        <input type="text" class="prc-stock {{ array_key_exists($sk, $dirty) ? 'dirty' : '' }} {{ isset($problems[$sk]) ? 'bad' : '' }}" wire:model.blur="cells.{{ $sk }}" placeholder="∞" title="{{ $problems[$sk] ?? 'Empty = unlimited' }}">
                                    </div>
                                </td>
                            @endif
                            <td style="white-space:nowrap">@if ($plan){{ \Paymenter\Extensions\Others\Pricing\Services\PriceEditor::periodLabel($plan) }}<br><span class="prc-hint">{{ $plan->name }}</span>@else<span class="prc-hint">no paid plans</span>@endif</td>
                            @foreach ($cols as $c)
                                <td>
                                    @if ($plan)
                                        @php $kp = "p{$plan->id}_{$c}_price"; $ks = "p{$plan->id}_{$c}_setup"; @endphp
                                        <div class="prc-cell">
                                            <input type="text" class="{{ array_key_exists($kp, $dirty) ? 'dirty' : '' }} {{ isset($problems[$kp]) ? 'bad' : '' }}" wire:model.blur="cells.{{ $kp }}" placeholder="not sold" title="{{ $problems[$kp] ?? '' }}">
                                            <input type="text" class="setup {{ array_key_exists($ks, $dirty) ? 'dirty' : '' }} {{ isset($problems[$ks]) ? 'bad' : '' }}" wire:model.blur="cells.{{ $ks }}" placeholder="setup 0" title="{{ $problems[$ks] ?? 'Setup fee' }}">
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="{{ 4 + count($cols) }}" style="padding:20px;text-align:center" class="prc-hint">No products match these filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($pages > 1)
            <div class="prc-row" style="justify-content:center">
                <button type="button" class="prc-btn" wire:click="goPage({{ $page - 1 }})" @disabled($page <= 1)>‹ Previous</button>
                <span class="prc-hint" style="align-self:center">Page {{ $page }} of {{ $pages }} · {{ $all->count() }} products</span>
                <button type="button" class="prc-btn" wire:click="goPage({{ $page + 1 }})" @disabled($page >= $pages)>Next ›</button>
            </div>
        @else
            <p class="prc-hint" style="text-align:center;margin:0">{{ $all->count() }} product(s). Empty price = not sold in that currency · empty stock = unlimited.</p>
        @endif

        @if ($dirty)
            <div class="prc-bar">
                <span><b>{{ count($dirty) }}</b> unsaved change(s){{ $problems ? ' · ' . count($problems) . ' to fix (red)' : '' }}. New orders use the new prices; existing customers keep theirs.</span>
                <span style="display:flex;gap:8px">
                    <button type="button" class="prc-btn" wire:click="discard" wire:confirm="Forget the unsaved changes?">Discard</button>
                    <button type="button" class="prc-btn primary" wire:click="save" wire:loading.attr="disabled">Save</button>
                </span>
            </div>
        @endif
    </div>
</x-filament-panels::page>
