<x-filament-panels::page>
    @php $rows = $this->getRows(); $tz = \Paymenter\Extensions\Others\Pricing\Pricing::instance()->tz(); @endphp
    <style>
        .pro{display:grid;gap:14px;max-width:1150px}
        .pro-box{border:1px solid rgba(127,127,127,.25);border-radius:14px;padding:16px;background:rgba(127,127,127,.04);display:grid;gap:12px}
        .pro-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
        .pro label{display:flex;flex-direction:column;gap:4px;font-size:.82rem;font-weight:600}
        .pro input[type=text],.pro input[type=number],.pro input[type=datetime-local],.pro input[type=search],.pro select{padding:7px 9px;border-radius:8px;border:1px solid rgba(127,127,127,.35);background:transparent;color:inherit;font:inherit;font-weight:400}
        .pro select option{color:#111}
        .pro .chk{flex-direction:row!important;align-items:center;gap:8px!important;font-weight:500!important}
        .pro-hint{font-size:.78rem;opacity:.7;font-weight:400}
        .pro .err{color:#dc2626;font-size:.76rem;font-weight:500}
        .pro-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:9px;font-weight:600;font-size:.85rem;border:1px solid rgba(127,127,127,.35)}
        .pro-btn.primary{background:var(--primary-600,#4f46e5);color:#fff;border-color:transparent}
        .pro table{width:100%;border-collapse:collapse;font-size:.85rem}
        .pro td,.pro th{padding:8px 6px;border-bottom:1px solid rgba(127,127,127,.15);text-align:left;vertical-align:top}
        .pro th{opacity:.7;font-weight:600}
        .st{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:700}
        .st.active{background:rgba(22,163,74,.15);color:#16a34a}.st.scheduled{background:rgba(37,99,235,.15);color:#2563eb}
        .st.ended,.st.sold_out{background:rgba(127,127,127,.18)}
        .pro-pick{max-height:260px;overflow:auto;border:1px solid rgba(127,127,127,.2);border-radius:10px;padding:8px;display:grid;gap:4px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}
    </style>

    <div class="pro">
        @if ($editing === null)
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <p class="pro-hint" style="margin:0;font-size:.85rem">A promotion gives a % off on chosen products for a limited time and/or for the first N orders ("only 10 at this price"). It is applied in the cart automatically; customers see the discount on the invoice. Orders already placed keep their discount for the payments you choose.</p>
                <button type="button" class="pro-btn primary" wire:click="create">New promotion</button>
            </div>
            <div class="pro-box" style="padding:6px 12px">
                <table>
                    <thead><tr><th>Promotion</th><th>Discount</th><th>When</th><th>Orders</th><th>Products</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="promo-{{ $r['id'] }}">
                            <td><b>{{ $r['name'] }}</b><div class="pro-hint">code {{ $r['code'] }}{{ $r['auto'] ? ' · automatic' : ' · code only' }}</div></td>
                            <td>{{ $r['discount'] }}<div class="pro-hint">{{ $r['cycles'] }}</div></td>
                            <td style="white-space:nowrap">{{ $r['window'] }}</td>
                            <td>{{ $r['used'] }}{{ $r['max'] ? ' / ' . $r['max'] : '' }}@if ($r['max'])<div class="pro-hint">{{ max(0, $r['max'] - $r['used']) }} left</div>@endif</td>
                            <td>{{ $r['products'] }}</td>
                            <td><span class="st {{ $r['status'] }}">{{ ['active' => 'Running', 'scheduled' => 'Scheduled', 'ended' => 'Ended', 'sold_out' => 'Sold out'][$r['status']] }}</span></td>
                            <td style="white-space:nowrap;text-align:right">
                                <button type="button" class="pro-btn" style="padding:3px 10px" wire:click="edit({{ $r['id'] }})">Edit</button>
                                @if (in_array($r['status'], ['active', 'scheduled']))<button type="button" class="pro-btn" style="padding:3px 10px" wire:click="endNow({{ $r['id'] }})" wire:confirm="End this promotion now?">End now</button>@endif
                                <button type="button" class="pro-btn" style="padding:3px 10px;color:#dc2626" wire:click="remove({{ $r['id'] }})" wire:confirm="Delete this promotion? (If it was used, it is only ended.)">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="pro-hint" style="padding:20px;text-align:center">No promotions yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="pro-box">
                <h3 style="margin:0;font-weight:700">{{ $editing ? 'Edit promotion' : 'New promotion' }}</h3>
                <div class="pro-grid">
                    <label>Name (customers see it)<input type="text" wire:model="form.name" placeholder="Black Friday">@error('form.name')<span class="err">{{ $message }}</span>@enderror</label>
                    <label>Code <span class="pro-hint">empty = made from the name</span><input type="text" wire:model="form.code" placeholder="BLACKFRIDAY">@error('form.code')<span class="err">{{ $message }}</span>@enderror</label>
                    <label>Discount on
                        <select wire:model.live="form.kind"><option value="price">The price</option><option value="all">Price and setup fee</option><option value="free_setup">Free setup fee only</option></select>
                    </label>
                    @if (($form['kind'] ?? '') !== 'free_setup')
                        <label>% off<input type="number" min="1" max="100" step="0.5" wire:model="form.percent">@error('form.percent')<span class="err">{{ $message }}</span>@enderror</label>
                        <label>For
                            <select wire:model="form.cycles"><option value="1">The first payment only</option><option value="3">The first 3 payments</option><option value="6">The first 6 payments</option><option value="12">The first 12 payments</option><option value="0">Every payment (forever)</option></select>
                        </label>
                    @endif
                </div>
                <div class="pro-grid">
                    <label>Starts <span class="pro-hint">{{ $tz }}</span><input type="datetime-local" wire:model="form.starts_at">@error('form.starts_at')<span class="err">{{ $message }}</span>@enderror</label>
                    <label>Ends <span class="pro-hint">empty = no end</span><input type="datetime-local" wire:model="form.expires_at">@error('form.expires_at')<span class="err">{{ $message }}</span>@enderror</label>
                    <label>Only for the first … orders <span class="pro-hint">empty = no limit</span><input type="number" min="1" wire:model="form.max_uses" placeholder="10">@error('form.max_uses')<span class="err">{{ $message }}</span>@enderror</label>
                    <label>Per customer <span class="pro-hint">empty = no limit</span><input type="number" min="1" wire:model="form.per_user">@error('form.per_user')<span class="err">{{ $message }}</span>@enderror</label>
                </div>
                <div class="pro-grid">
                    <label class="chk"><input type="checkbox" wire:model="form.auto_apply"> Apply automatically in the cart (no code needed)</label>
                    <label class="chk"><input type="checkbox" wire:model="form.show_badge"> Show a sale badge in the Marketplace</label>
                    <label class="chk"><input type="checkbox" wire:model.live="form.all_products"> All products</label>
                </div>
                @if (empty($form['all_products']))
                    <div style="display:grid;gap:8px">
                        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                            <label style="flex:1;min-width:200px">Products <span class="pro-hint">{{ count($form['products'] ?? []) }} selected</span><input type="search" wire:model.live.debounce.300ms="productSearch" placeholder="Search…"></label>
                            @if ($fams = $this->getFamilies())
                                <label>Add a Marketplace family<select wire:model.live="pickFamily"><option value="">—</option>@foreach ($fams as $fm)<option value="{{ $fm }}">{{ $fm }}</option>@endforeach</select></label>
                            @endif
                            <button type="button" class="pro-btn" wire:click="pickShown(true)">Select shown</button>
                            <button type="button" class="pro-btn" wire:click="pickShown(false)">Unselect shown</button>
                        </div>
                        <div class="pro-pick">
                            @foreach ($this->getPickable() as $pp)
                                <label class="chk" wire:key="pick-{{ $pp['id'] }}"><input type="checkbox" wire:model.live="form.products" value="{{ $pp['id'] }}"> {{ $pp['name'] }} <span class="pro-hint">{{ $pp['cat'] }}</span></label>
                            @endforeach
                        </div>
                        @error('form.products')<span class="err">{{ $message }}</span>@enderror
                    </div>
                @endif
                <label>Note (only for you)<input type="text" wire:model="form.note"></label>
                <div style="display:flex;gap:8px">
                    <button type="button" class="pro-btn primary" wire:click="savePromotion" wire:loading.attr="disabled">Save promotion</button>
                    <button type="button" class="pro-btn" wire:click="cancel">Cancel</button>
                </div>
                <p class="pro-hint" style="margin:0">The discount is a percentage so it works the same in R$ and US$. Paymenter applies it to the first invoice and to the renewals you chose; after that the normal price is charged.</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
