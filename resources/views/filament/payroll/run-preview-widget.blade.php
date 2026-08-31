<x-filament-widgets::widget>
    <x-filament::section :heading="__('payroll.runs.preview.title')">
        <div class="erp-report__scroll">
            <table class="erp-report__table">
                <thead>
                    <tr>
                        <th>{{ __('payroll.runs.preview.employee') }}</th>
                        <th class="erp-report__num">{{ __('payroll.runs.preview.gross') }}</th>
                        <th class="erp-report__num">{{ __('payroll.runs.preview.gosi') }}</th>
                        <th class="erp-report__num">{{ __('payroll.runs.preview.deductions') }}</th>
                        <th class="erp-report__num">{{ __('payroll.runs.preview.recovery') }}</th>
                        <th class="erp-report__num">{{ __('payroll.runs.preview.net') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="erp-report__row">
                            <td>{{ $row['employee']->fullName() }}</td>
                            <td class="erp-report__num">{{ number_format((float) $row['gross'], 2) }}</td>
                            <td class="erp-report__num">{{ number_format((float) $row['gosi_employee'], 2) }}</td>
                            <td class="erp-report__num">{{ number_format((float) $row['deductions_total'], 2) }}</td>
                            <td class="erp-report__num">{{ number_format((float) $row['advance_recovery'], 2) }}</td>
                            <td class="erp-report__num" style="font-weight: 700;">{{ number_format((float) $row['net'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="erp-report__empty">
                                {{ __('payroll.runs.preview.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows !== [])
            <div style="margin-top: 0.75rem; font-weight: 700;">
                {{ __('payroll.runs.preview.total', ['total' => number_format((float) $total, 2)]) }}
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
