<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="fi-form-actions">
            <x-filament::button type="submit">
                {{ __('company.settings.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
