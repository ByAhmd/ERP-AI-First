<x-filament-widgets::widget>
    <x-filament::section>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem;">
            @foreach ($figures as $label => $value)
                <div>
                    <div style="font-size: 0.75rem; opacity: 0.7;">{{ $label }}</div>
                    <div style="font-weight: 700; font-size: 1.1rem;">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    @if ($projection !== [])
        <x-filament::section
            collapsible
            collapsed
            :heading="__('assets.register.schedule.title')"
            :description="__('assets.register.schedule.hint')"
        >
            <div class="erp-report__scroll">
                <table class="erp-report__table">
                    <thead>
                        <tr>
                            <th>{{ __('assets.register.schedule.period') }}</th>
                            <th class="erp-report__num">{{ __('assets.register.schedule.days') }}</th>
                            <th class="erp-report__num">{{ __('assets.register.schedule.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($projection as $row)
                            <tr class="erp-report__row">
                                <td>{{ $row['period']->name }}</td>
                                <td class="erp-report__num">{{ $row['days'] }}</td>
                                <td class="erp-report__num">{{ number_format((float) $row['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
