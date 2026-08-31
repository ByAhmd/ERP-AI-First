@php
    $report = $this->getReport();
    $base = $this->langBase();
    $money = fn ($amount) => (float) $amount == 0.0 ? '—' : number_format((float) $amount, 2);
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <div class="erp-report__scroll">
            <table class="erp-report__table">
                <thead>
                    <tr>
                        <th>{{ __($base.'.contact') }}</th>
                        <th>{{ __($base.'.code') }}</th>
                        <th class="erp-report__num">{{ __($base.'.open_invoices') }}</th>
                        <th class="erp-report__num">{{ __($base.'.unapplied_notes') }}</th>
                        <th class="erp-report__num">{{ __($base.'.unused_vouchers') }}</th>
                        <th class="erp-report__num">{{ __($base.'.net') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr class="erp-report__row">
                            <td>{{ $row->name }}</td>
                            <td class="erp-report__num">{{ $row->code ?? '—' }}</td>
                            <td class="erp-report__num">{{ $money($row->openInvoices) }}</td>
                            <td class="erp-report__num">{{ $money($row->unappliedNotes) }}</td>
                            <td class="erp-report__num">{{ $money($row->unusedVouchers) }}</td>
                            <td class="erp-report__num">{{ number_format((float) $row->net, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="erp-report__empty">
                                {{ __($base.'.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($report['rows'] !== [])
                    <tfoot>
                        <tr class="erp-report__row--total">
                            <td colspan="2">{{ __($base.'.totals') }}</td>
                            <td class="erp-report__num">{{ $money($report['totals']['open_invoices']) }}</td>
                            <td class="erp-report__num">{{ $money($report['totals']['unapplied_notes']) }}</td>
                            <td class="erp-report__num">{{ $money($report['totals']['unused_vouchers']) }}</td>
                            <td class="erp-report__num">{{ number_format((float) $report['totals']['net'], 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
