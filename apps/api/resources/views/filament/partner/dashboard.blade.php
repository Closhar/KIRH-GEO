<x-filament-panels::page>
    @include('filament.partials.form-style')
    <div class="kg-grid">
        @foreach(['referred_count'=>'Привлечённых пространств','pending_minor'=>'На удержании, ₽','payable_minor'=>'Доступно к выплате, ₽','paid_minor'=>'Выплачено, ₽'] as $key=>$label)
        <section class="kg-section"><h2>{{ $label }}</h2><p style="font-size:1.6rem">{{ $key === 'referred_count' ? $summary[$key] : number_format($summary[$key]/100,2,',',' ') }}</p></section>
        @endforeach
    </div>
    <section class="kg-section"><h2>Кампании и условия</h2><p class="kg-hint">Пригласительная ссылка выдаётся при создании кампании; её секретная часть не отображается повторно. Комиссия начисляется с подтверждённых платежей, с учётом возвратов.</p>
    <div class="kg-table-wrap"><table class="kg-table"><thead><tr><th>Кампания</th><th>Программа</th><th>Комиссия</th><th>Удержание</th><th>Минимальная выплата</th></tr></thead><tbody>
    @forelse($campaigns as $campaign)<tr><td>{{ $campaign->campaign }}</td><td>{{ $campaign->name }} v{{ $campaign->version }}</td><td>{{ $campaign->rate_bps !== null ? $campaign->rate_bps/100 .'%' : number_format($campaign->fixed_minor/100,2,',',' ').' ₽' }}</td><td>{{ $campaign->hold_days }} дней</td><td>{{ number_format($campaign->minimum_payout_minor/100,2,',',' ') }} ₽</td></tr>@empty<tr><td colspan="5">Кампаний пока нет.</td></tr>@endforelse
    </tbody></table></div></section>
    <section class="kg-section"><h2>Выплаты</h2><div class="kg-table-wrap"><table class="kg-table"><thead><tr><th>Дата запроса</th><th>Сумма</th><th>Статус</th><th>Дата выплаты</th></tr></thead><tbody>
    @forelse($payouts as $payout)<tr><td>{{ $payout->requested_at }}</td><td>{{ number_format($payout->amount_minor/100,2,',',' ') }} {{ $payout->currency }}</td><td>{{ $payout->status }}</td><td>{{ $payout->paid_at ?? '—' }}</td></tr>@empty<tr><td colspan="4">Выплат пока нет.</td></tr>@endforelse
    </tbody></table></div></section>
</x-filament-panels::page>
