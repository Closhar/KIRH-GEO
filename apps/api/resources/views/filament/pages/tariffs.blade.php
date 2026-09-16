<x-filament-panels::page>
    @include('filament.partials.form-style')
    <p>Изменение создаёт новую версию. Оплаченные условия остаются привязаны к своей версии. Все суммы указаны в копейках, валюта RUB.</p>
    <div class="kg-form">
        <form wire:submit="saveDraft" class="kg-section"><h2>Новый тариф или новая версия</h2><div class="kg-grid">
            @include('filament.partials.field',['name'=>'code','label'=>'Системный код (латиница)'])
            @include('filament.partials.field',['name'=>'name','label'=>'Название'])
            @include('filament.partials.field',['name'=>'month_minor','label'=>'Месяц, копейки','type'=>'number','min'=>0])
            @include('filament.partials.field',['name'=>'year_minor','label'=>'Год, копейки','type'=>'number','min'=>0])
            @include('filament.partials.field',['name'=>'provider','label'=>'Платёжный провайдер','type'=>'select','options'=>['sandbox'=>'Тестовый','yookassa'=>'ЮKassa']])
        </div><h2 style="margin-top:1rem">Возможности и лимиты API</h2><div class="kg-grid">
        @foreach($features as $feature)
            @include('filament.partials.field',['name'=>'features.'.$feature->id,'label'=>$feature->key,'type'=>$feature->value_type === 'boolean' ? 'checkbox' : 'number','min'=>0])
        @endforeach
        </div><div class="kg-actions"><x-filament::button type="submit">Сохранить новую версию-черновик</x-filament::button></div></form>
        @include('filament.partials.reason')
        <section class="kg-section"><h2>Версии тарифов</h2><p class="kg-hint">«Заполнить форму» позволяет посмотреть значения и создать следующую версию. Публикация применяет уже сохранённый черновик.</p><div class="kg-table-wrap"><table class="kg-table"><thead><tr><th>Тариф</th><th>Версия</th><th>Статус</th><th>Действия</th></tr></thead><tbody>
        @foreach($plans as $plan)<tr><td>{{ $plan->name }} ({{ $plan->code }})</td><td>{{ $plan->version }}</td><td>{{ $plan->status }}</td><td><div class="kg-actions"><x-filament::button color="gray" wire:click="loadPlan('{{ $plan->id }}')">Заполнить форму</x-filament::button>@if($plan->status === 'draft')<x-filament::button wire:click="publish('{{ $plan->id }}')">Опубликовать</x-filament::button>@endif</div></td></tr>@endforeach
        </tbody></table></div></section>
        <form wire:submit="saveTrial" class="kg-section"><h2>Пробный доступ</h2>
            @include('filament.partials.field',['name'=>'trialDays','binding'=>'trialDays','label'=>'Дней пробного доступа (0 — отключён)','type'=>'number','min'=>0,'max'=>90])
            @include('filament.partials.field',['name'=>'trialPlanId','binding'=>'trialPlanId','label'=>'Тариф пробного доступа','type'=>'select','options'=>[''=>'Выберите тариф'] + $plans->where('status','published')->mapWithKeys(fn($p)=>[$p->id=>$p->name.' v'.$p->version])->all()])
            <div class="kg-actions"><x-filament::button type="submit">Сохранить длительность триала</x-filament::button></div>
        </form>
    </div>
</x-filament-panels::page>
