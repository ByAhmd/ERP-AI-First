@php
    $report = $this->getReport();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div class="erp-report__scroll">
            <table class="erp-report__table">
                <thead>
                    <tr>
                        <th>{{ __('payroll.tie.account') }}</th>
                        <th>{{ __('payroll.tie.role') }}</th>
                        <th class="erp-report__num">{{ __('payroll.tie.gl_balance') }}</th>
                        <th class="erp-report__num">{{ __('payroll.tie.register_total') }}</th>
                        <th class="erp-report__num">{{ __('payroll.tie.difference') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr class="erp-report__row">
                            <td>{{ $row['account']->displayName() }}</td>
                            <td>
                                {{ __('payroll.tie.roles.' . $row['role']) }}
                                @if ($row['informational'])
                                    <span style="opacity: 0.6; font-size: 0.8em;">
                                        — {{ __('payroll.tie.informational') }}
                                    </span>
                                @endif
                            </td>
                            <td class="erp-report__num">{{ number_format((float) $row['gl_balance'], 2) }}</td>
                            <td class="erp-report__num">{{ number_format((float) $row['register_total'], 2) }}</td>
                            <td class="erp-report__num" @if (! $row['informational'] && bccomp($row['difference'], '0', 4) !== 0) style="color: rgb(220 38 38); font-weight: 700;" @endif>
                                {{ number_format((float) $row['difference'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="margin-top: 0.75rem; font-size: 0.85rem; opacity: 0.75;">
            {{ $report['balanced'] ? __('payroll.tie.balanced') : __('payroll.tie.unbalanced') }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
