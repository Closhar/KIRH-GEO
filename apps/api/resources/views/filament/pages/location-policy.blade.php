<x-filament-panels::page>
    @include('filament.partials.form-style')
    <p>Целевые интервалы передаются приложению; фактическая частота зависит от разрешений, движения, заряда и ограничений ОС. Доступность истории ограничена также тарифом и выбором отправителя.</p>
    <form wire:submit="save" class="kg-form">
        <section class="kg-section"><h2>История и очередь</h2><div class="kg-grid">
        @foreach(['history_max_days'=>'Максимальная глубина истории, дней','history_default_days'=>'Глубина по умолчанию, дней','batch_max_points'=>'Максимум точек в батче','batch_max_bytes'=>'Максимум байт в батче','offline_max_hours'=>'Окно офлайн-выгрузки, часов','future_tolerance_seconds'=>'Допуск часов устройства, секунд','current_ttl_seconds'=>'TTL текущей позиции, секунд','realtime_max_age_seconds'=>'Возраст точки для real-time, секунд'] as $name=>$label)
            @include('filament.partials.field', ['name'=>$name,'label'=>$label,'type'=>'number','min'=>0])
        @endforeach
        </div></section>
        <section class="kg-section"><h2>Режимы Location Engine</h2><div class="kg-table-wrap"><table class="kg-table"><thead><tr><th>Режим</th><th>Получение GPS, сек</th><th>Отправка батча, сек</th></tr></thead><tbody>
        @foreach(['idle'=>'Покой','normal'=>'Обычный','live'=>'Live','sport'=>'Спорт','sos'=>'SOS'] as $mode=>$label)
            <tr><td>{{ $label }}</td><td><input aria-label="{{ $label }} получение GPS" class="kg-input" type="number" min="1" max="3600" wire:model="data.modes.{{ $mode }}.capture_seconds"></td><td><input aria-label="{{ $label }} отправка" class="kg-input" type="number" min="1" max="3600" wire:model="data.modes.{{ $mode }}.upload_seconds"></td></tr>
        @endforeach
        </tbody></table></div></section>
        @include('filament.partials.reason')
        <div><x-filament::button type="submit">Сохранить политику</x-filament::button></div>
    </form>
</x-filament-panels::page>
