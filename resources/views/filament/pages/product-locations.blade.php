@php
    $report = $this->getReport();
    $money = fn ($amount) => (float) $amount == 0.0 ? '—' : number_format((float) $amount, 2);
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <div class="erp-report__scroll">
            <table class="erp-report__table">
                <thead>
                    <tr>
                        <th>{{ __('inventory.locations_report.sku') }}</th>
                        <th>{{ __('inventory.locations_report.product') }}</th>
                        @foreach ($report['branches'] as $branch)
                            <th class="erp-report__num">{{ $branch->displayName() }}</th>
                        @endforeach
                        <th class="erp-report__num">{{ __('inventory.locations_report.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr class="erp-report__row">
                            <td class="erp-report__num">{{ $row['sku'] ?? '—' }}</td>
                            <td>{{ $row['name'] }}</td>
                            @foreach ($report['branches'] as $branch)
                                <td class="erp-report__num">{{ $money($row['quantities'][$branch->getKey()]) }}</td>
                            @endforeach
                            <td class="erp-report__num">{{ number_format((float) $row['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 3 + count($report['branches']) }}" class="erp-report__empty">
                                {{ __('inventory.locations_report.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($report['rows'] !== [])
                    <tfoot>
                        <tr class="erp-report__row--total">
                            <td colspan="2">{{ __('inventory.locations_report.totals') }}</td>
                            @foreach ($report['branches'] as $branch)
                                <td class="erp-report__num">{{ $money($report['totals'][$branch->getKey()]) }}</td>
                            @endforeach
                            <td class="erp-report__num">{{ number_format((float) $report['totals']['total'], 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
