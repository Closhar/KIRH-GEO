<x-filament-panels::page>
    @include('filament.partials.form-style')
    <p>Метаданные для поддержки. Геопозиции, содержимое уведомлений, токены и платёжные реквизиты здесь недоступны.</p>
    <div class="kg-actions">
        @foreach(['users'=>'Пользователи','workspaces'=>'Пространства','subscriptions'=>'Подписки','usage'=>'Использование','audit'=>'Аудит'] as $key=>$label)
            <x-filament::button :color="$section === $key ? 'primary' : 'gray'" wire:click="selectSection('{{ $key }}')">{{ $label }}</x-filament::button>
        @endforeach
    </div>
    <form wire:submit="applyFilter" class="kg-form"><label class="kg-field"><span>{{ $section === 'users' ? 'ID пользователя' : 'ID пространства' }} (пусто — все)</span><input class="kg-input" wire:model="filterId" placeholder="UUID"></label><div><x-filament::button type="submit">Найти</x-filament::button></div></form>
    <section class="kg-section"><div class="kg-table-wrap"><table class="kg-table"><thead><tr>@foreach($columns as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
    @forelse($rows as $row)<tr>@foreach($columns as $field=>$label)<td>{{ $row->{$field} ?? '—' }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($columns) }}">Записей нет.</td></tr>@endforelse
    </tbody></table></div></section>
    <div class="kg-actions"><x-filament::button color="gray" wire:click="previousPage" :disabled="$pageNumber <= 1">Назад</x-filament::button><span>Страница {{ $pageNumber }}</span><x-filament::button color="gray" wire:click="nextPage" :disabled="!$hasMore">Далее</x-filament::button></div>
</x-filament-panels::page>
