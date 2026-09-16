<x-filament-panels::page>
    @include('filament.partials.form-style')
    <p>Тестовый доступ выдаёт права напрямую, без создания подписки или применения тарифа. При включении права применяются ко всем активным пространствам, при выключении — отзываются.</p>

    <form wire:submit="save" class="kg-form">
        <section class="kg-section">
            <h2>Режим</h2>
            <label class="kg-field">
                <span>Тестовый доступ включён</span>
                <input type="checkbox" wire:model="enabled" style="width:1.25rem;height:1.25rem">
            </label>
        </section>

        <section class="kg-section">
            <h2>Возможности и лимиты тестового режима</h2>
            <div class="kg-grid">
                @foreach($features as $feature)
                    @include('filament.partials.field', [
                        'name' => 'features.'.$feature->id,
                        'label' => \App\Filament\Support\FeatureLabels::label($feature->key),
                        'type' => $feature->value_type === 'boolean' ? 'checkbox' : 'number',
                        'min' => 0,
                    ])
                @endforeach
            </div>
        </section>

        @include('filament.partials.reason')
        <div><x-filament::button type="submit">Сохранить тестовый доступ</x-filament::button></div>
    </form>
</x-filament-panels::page>
