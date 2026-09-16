<x-filament-panels::page>
    @include('filament.partials.form-style')
    <form wire:submit="save" class="kg-form">
        <section class="kg-section"><h2>Создать промокод</h2><div class="kg-grid">
            @include('filament.partials.field',['name'=>'code','label'=>'Промокод (сохраните перед созданием)'])
            @include('filament.partials.field',['name'=>'label','label'=>'Внутреннее название'])
            @include('filament.partials.field',['name'=>'starts_at','label'=>'Начало действия (UTC)','type'=>'datetime-local'])
            @include('filament.partials.field',['name'=>'ends_at','label'=>'Конец действия (UTC)','type'=>'datetime-local'])
            @include('filament.partials.field',['name'=>'max_uses','label'=>'Всего применений','type'=>'number','min'=>1])
            @include('filament.partials.field',['name'=>'per_user_limit','label'=>'На пользователя','type'=>'number','min'=>1])
            @include('filament.partials.field',['name'=>'per_workspace_limit','label'=>'На пространство','type'=>'number','min'=>1])
            @include('filament.partials.field',['name'=>'type','label'=>'Тип бонуса','type'=>'select','options'=>['free_access'=>'Бесплатный доступ','discount'=>'Скидка','free_months'=>'Бесплатные месяцы']])
            @include('filament.partials.field',['name'=>'plan_id','label'=>'Тариф бонуса','type'=>'select','options'=>[''=>'Выберите тариф'] + $plans->mapWithKeys(fn($p)=>[$p->id=>$p->name.' v'.$p->version])->all()])
            @if($data['type'] === 'free_access')
                @include('filament.partials.field',['name'=>'duration_days','label'=>'Бесплатных дней','type'=>'number','min'=>1,'max'=>365])
            @elseif($data['type'] === 'free_months')
                @include('filament.partials.field',['name'=>'months','label'=>'Бесплатных месяцев','type'=>'number','min'=>1,'max'=>24])
            @else
                @include('filament.partials.field',['name'=>'discount_kind','label'=>'Расчёт скидки','type'=>'select','options'=>['percent'=>'Процент','fixed'=>'Копейки']])
                @include('filament.partials.field',['name'=>'discount_value','label'=>'Значение скидки','type'=>'number','min'=>1])
            @endif
            @include('filament.partials.field',['name'=>'cycles','label'=>'Платёжных циклов','type'=>'number','min'=>1,'max'=>24])
        </div></section>
        @include('filament.partials.reason')
        <div><x-filament::button type="submit">Создать промокод</x-filament::button></div>
    </form>
    <section class="kg-section"><h2>Промокоды</h2><div class="kg-table-wrap"><table class="kg-table"><thead><tr><th>Название</th><th>Применения</th><th>Действует до</th><th>Действие</th></tr></thead><tbody>
    @foreach($promos as $promo)<tr><td>{{ $promo->label }}</td><td>{{ $promo->uses }} / {{ $promo->max_uses }}</td><td>{{ $promo->ends_at }}</td><td>@if($promo->active)<x-filament::button color="danger" wire:click="deactivate('{{ $promo->id }}')">Отключить</x-filament::button>@else Отключён @endif</td></tr>@endforeach
    </tbody></table></div></section>
</x-filament-panels::page>
