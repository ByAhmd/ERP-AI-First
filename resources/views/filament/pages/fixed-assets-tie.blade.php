@php
    $report = $this->getReport();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div class="erp-report__scroll">
            <table class="erp-report__table">
                <thead>
                    <tr>
                        <th>{{ __('assets.tie.account') }}</th>
                        <th>{{ __('assets.tie.role') }}</th>
                        <th class="erp-report__num">{{ __('assets.tie.gl_balance') }}</th>
                        <th class="erp-report__num">{{ __('assets.tie.register_total') }}</th>
                        <th class="erp-report__num">{{ __('assets.tie.difference') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr class="erp-report__row">
                            <td>{{ $row['account']?->displayName() ?? '—' }}</td>
                            <td>{{ __('assets.tie.roles.' . $row['role']) }}</td>
                            <td class="erp-report__num">{{ number_format((float) $row['gl_balance'], 2) }}</td>
                            <td class="erp-report__num">{{ number_format((float) $row['register_total'], 2) }}</td>
                            <td class="erp-report__num" @if (bccomp($row['difference'], '0', 4) !== 0) style="color: rgb(220 38 38); font-weight: 700;" @endif>
                                {{ number_format((float) $row['difference'], 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="erp-report__empty">
                                {{ __('assets.tie.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($report['rows'] !== [])
            <div style="margin-top: 0.75rem; font-size: 0.85rem; opacity: 0.75;">
                {{ $report['balanced'] ? __('assets.tie.balanced') : __('assets.tie.unbalanced') }}
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
