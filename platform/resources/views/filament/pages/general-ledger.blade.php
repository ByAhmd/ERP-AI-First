@php
    $account = $this->getAccount();
    $movements = $this->getMovements();
    $summary = $this->getSummary();
    $money = fn ($amount) => (float) $amount == 0.0 ? '—' : number_format((float) $amount, 2);
    // A balance is shown in the account's natural direction with its side
    // marked, so a payable at 5,000 credit reads as owed rather than negative.
    $signed = function ($amount) {
        $value = number_format(abs((float) $amount), 2);
        if ((float) $amount == 0.0) {
            return '—';
        }
        $side = (float) $amount > 0
            ? __('accounting.normal_balance.debit')
            : __('accounting.normal_balance.credit');
        return $value.' '.$side;
    };
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($account === null)
        <x-filament::section>
            <p style="text-align:center;color:var(--gray-500);padding:1.5rem;">
                {{ __('accounting.general_ledger.choose_account') }}
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:1rem;">
                <div>
                    <div style="font-size:.75rem;color:var(--gray-500);">{{ __('accounting.general_ledger.opening') }}</div>
                    <div style="font-size:1.125rem;font-weight:600;font-variant-numeric:tabular-nums;">{{ $signed($summary['opening']) }}</div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:var(--gray-500);">{{ __('accounting.normal_balance.debit') }}</div>
                    <div style="font-size:1.125rem;font-weight:600;font-variant-numeric:tabular-nums;">{{ $money($summary['debit']) }}</div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:var(--gray-500);">{{ __('accounting.normal_balance.credit') }}</div>
                    <div style="font-size:1.125rem;font-weight:600;font-variant-numeric:tabular-nums;">{{ $money($summary['credit']) }}</div>
                </div>
                <div>
                    <div style="font-size:.75rem;color:var(--gray-500);">{{ __('accounting.general_ledger.closing') }}</div>
                    <div style="font-size:1.125rem;font-weight:700;font-variant-numeric:tabular-nums;">{{ $signed($summary['closing']) }}</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div style="overflow-x:auto;">
                <table class="fi-ta-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:start;padding:.5rem;">{{ __('accounting.entries.columns.date') }}</th>
                            <th style="text-align:start;padding:.5rem;">{{ __('accounting.entries.columns.number') }}</th>
                            <th style="text-align:start;padding:.5rem;">{{ __('accounting.entries.columns.description') }}</th>
                            <th style="text-align:end;padding:.5rem;">{{ __('accounting.normal_balance.debit') }}</th>
                            <th style="text-align:end;padding:.5rem;">{{ __('accounting.normal_balance.credit') }}</th>
                            <th style="text-align:end;padding:.5rem;">{{ __('accounting.general_ledger.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- The opening balance is a row of its own: without it the
                             first movement's running balance looks unexplained. --}}
                        <tr style="border-top:1px solid var(--gray-200);font-style:italic;color:var(--gray-600);">
                            <td style="padding:.5rem;" colspan="5">{{ __('accounting.general_ledger.opening') }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $signed($summary['opening']) }}</td>
                        </tr>

                        @forelse ($movements as $movement)
                            <tr style="border-top:1px solid var(--gray-200);">
                                <td style="padding:.5rem;white-space:nowrap;">{{ $movement->date->translatedFormat('d M Y') }}</td>
                                <td style="padding:.5rem;font-variant-numeric:tabular-nums;">{{ $movement->number }}</td>
                                <td style="padding:.5rem;">
                                    {{ $movement->description ?? '—' }}
                                    @if ($movement->reference)
                                        <span style="color:var(--gray-500);font-size:.8125rem;">({{ $movement->reference }})</span>
                                    @endif
                                </td>
                                <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($movement->debit) }}</td>
                                <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($movement->credit) }}</td>
                                <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $signed($movement->balance) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="padding:2rem;text-align:center;color:var(--gray-500);">
                                    {{ __('accounting.general_ledger.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--gray-400);font-weight:700;">
                            <td colspan="3" style="padding:.5rem;">{{ __('accounting.general_ledger.closing') }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($summary['debit']) }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $money($summary['credit']) }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums;">{{ $signed($summary['closing']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
