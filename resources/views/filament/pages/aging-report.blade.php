@php
    $data = $this->getData();
    $reconciliation = $this->getReconciliation();
    $base = $this->langBase();
    $money = fn ($amount) => number_format((float) $amount, 2);
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    @if ($data->foreignCount > 0)
        <x-filament::section>
            <div class="erp-report__verdict fi-color-warning">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="erp-report__verdict-icon" />
                {{ __($base.'.foreign_warning', ['count' => $data->foreignCount]) }}
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <div class="erp-report__scroll">
            <table class="erp-report__table">
                <thead>
                    <tr>
                        <th>{{ __($base.'.contact') }}</th>
                        <th>{{ __($base.'.code') }}</th>
                        @foreach ($data->dates as $date)
                            <th class="erp-report__num">{{ $date->format('Y-m-d') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data->rows as $row)
                        <tr class="erp-report__row">
                            <td>{{ $row->name }}</td>
                            <td class="erp-report__num">{{ $row->code ?? '—' }}</td>
                            @foreach ($row->cells as $cell)
                                <td class="erp-report__num">
                                    {{ $money($cell['amount']) }} ({{ $cell['count'] }})
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 2 + count($data->dates) }}" class="erp-report__empty">
                                {{ __($base.'.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($data->rows !== [])
                    <tfoot>
                        <tr class="erp-report__row--total">
                            <td colspan="2">{{ __($base.'.totals') }}</td>
                            @foreach ($data->totals as $total)
                                <td class="erp-report__num">
                                    {{ $money($total['amount']) }} ({{ $total['count'] }})
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>

    {{-- The reconciliation footer: what the grid deliberately leaves out, so
         the reader can still tie its total to the control accounts. --}}
    @if ($reconciliation !== null)
        <x-filament::section>
            <div class="erp-report__scroll">
                <table class="erp-report__table">
                    <tbody>
                        <tr class="erp-report__row">
                            <td>{{ __($base.'.unapplied_notes') }}</td>
                            <td class="erp-report__num">{{ $money($reconciliation['unapplied_notes']) }}</td>
                        </tr>
                        <tr class="erp-report__row">
                            <td>{{ __($base.'.advances') }}</td>
                            <td class="erp-report__num">{{ $money($reconciliation['advances']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
