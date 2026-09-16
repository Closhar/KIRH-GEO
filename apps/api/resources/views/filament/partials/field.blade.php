@php($binding = $binding ?? 'data.'.$name)
<label class="kg-field">
    <span>{{ $label }}</span>
    @if(($type ?? 'text') === 'select')
        <select class="kg-input" wire:model.live="{{ $binding }}">
            @foreach($options as $value => $caption)<option value="{{ $value }}">{{ $caption }}</option>@endforeach
        </select>
    @elseif(($type ?? 'text') === 'checkbox')
        <input type="checkbox" wire:model="{{ $binding }}" style="width:1.25rem;height:1.25rem">
    @else
        <input class="kg-input" type="{{ $type ?? 'text' }}" wire:model="{{ $binding }}" @isset($min) min="{{ $min }}" @endisset @isset($max) max="{{ $max }}" @endisset @if(($type ?? '') === 'number') step="1" @endif>
    @endif
</label>
