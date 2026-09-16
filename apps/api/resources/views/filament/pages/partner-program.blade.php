<x-filament-panels::page>
    @include('filament.partials.form-style')
    <p>Условия версионируются. Уже начисленные комиссии сохраняют свою версию. Выплаты требуют отдельного исполнения и учёта возвратов. Исключение промокодов отключает комиссию по платежам пространств, где ранее применялся промокод.</p>
    <form wire:submit="saveDraft" class="kg-form"><section class="kg-section"><h2>Условия новой версии</h2><div class="kg-grid">
        @include('filament.partials.field',['name'=>'name','label'=>'Название программы'])
        @include('filament.partials.field',['name'=>'commission_kind','label'=>'Расчёт комиссии','type'=>'select','options'=>['percent'=>'Процент','fixed'=>'Копейки']])
        @include('filament.partials.field',['name'=>'commission_value','label'=>'Значение комиссии','type'=>'number','min'=>0])
        @include('filament.partials.field',['name'=>'hold_days','label'=>'Задержка начисления, дней','type'=>'number','min'=>1,'max'=>365])
        @include('filament.partials.field',['name'=>'minimum_payout_minor','label'=>'Минимальная выплата, копейки','type'=>'number','min'=>1])
        @include('filament.partials.field',['name'=>'attribution_window_days','label'=>'Окно атрибуции, дней','type'=>'number','min'=>1,'max'=>365])
        @include('filament.partials.field',['name'=>'recurring','label'=>'Комиссия с повторных платежей','type'=>'checkbox'])
        @include('filament.partials.field',['name'=>'commission_max_cycles','label'=>'Максимум оплаченных циклов','type'=>'number','min'=>1,'max'=>120])
        @include('filament.partials.field',['name'=>'exclude_promos','label'=>'Исключать платежи с промокодом','type'=>'checkbox'])
    </div></section>
    @include('filament.partials.reason')
    <div><x-filament::button type="submit">Сохранить черновик условий</x-filament::button></div></form>
    <section class="kg-section"><h2>Версии</h2><div class="kg-table-wrap"><table class="kg-table"><thead><tr><th>Программа</th><th>Версия</th><th>Комиссия</th><th>Статус</th><th>Действие</th></tr></thead><tbody>
    @foreach($versions as $version)<tr><td>{{ $version->name }}</td><td>{{ $version->version }}</td><td>{{ $version->rate_bps !== null ? $version->rate_bps / 100 . '%' : $version->fixed_minor . ' коп.' }}</td><td>{{ $version->status }}</td><td><div class="kg-actions"><x-filament::button color="gray" wire:click="loadVersion('{{ $version->id }}')">Заполнить форму</x-filament::button>@if($version->status === 'draft')<x-filament::button wire:click="publish('{{ $version->id }}')">Опубликовать</x-filament::button>@endif</div></td></tr>@endforeach
    </tbody></table></div></section>
</x-filament-panels::page>
