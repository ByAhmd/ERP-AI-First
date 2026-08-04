@php
    $statement = $this->getStatement();
    $balanced = $statement->isBalanced();

    // Accounting convention: a negative figure is parenthesised rather than
    // signed. A minus sign leading an Arabic right-to-left line is easy to lose;
    // brackets are unambiguous in either direction.
    $money = function (string $amount): string {
        if (bccomp($amount, '0', 4) === 0) {
            return '—';
        }

        $formatted = number_format((float) ltrim($amount, '-'), 2);

        return str_starts_with($amount, '-') ? '('.$formatted.')' : $formatted;
    };

    $isNegative = fn (string $amount): bool => bccomp($amount, '0', 4) < 0;
@endphp

<x-filament-panels::page>
    {{ $this->form }}

    {{-- A balance sheet that does not balance invalidates itself, so the
         verdict is stated before the figures rather than left to be discovered
         by adding up two totals. --}}
    @if ($balanced === false)
        <x-filament::section>
            <div class="erp-report__verdict fi-color-danger">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="erp-report__verdict-icon" />
                {{ __('accounting.statements.out_of_balance', [
                    'difference' => number_format((float) $statement->largestImbalance(), 2),
                ]) }}
            </div>
        </x-filament::section>
    @elseif ($statement->imbalance !== null && $statement->isFiltered)
        <x-filament::section>
            <div class="erp-report__verdict erp-report__verdict--muted">
                <x-filament::icon icon="heroicon-o-information-circle" class="erp-report__verdict-icon" />
                {{ __('accounting.statements.filtered_notice') }}
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <div class="erp-report__scroll">
            <table class="erp-report__table erp-statement">
                <thead>
                    <tr>
                        <th class="erp-statement__label">{{ __('accounting.statements.account') }}</th>
                        @foreach ($statement->periods as $period)
                            <th class="erp-report__num">{{ $period->label }}</th>
                        @endforeach
                    </tr>
                </thead>

                @foreach ($statement->sections as $section)
                    <tbody class="erp-statement__section">
                        @if ($section->isSummary)
                            <tr @class([
                                'erp-statement__summary',
                                'erp-statement__summary--emphasised' => $section->isEmphasised,
                            ])>
                                <th scope="row" class="erp-statement__label">{{ $section->title() }}</th>
                                @foreach ($section->totals as $amount)
                                    <td @class(['erp-report__num', 'erp-statement__amount--negative' => $isNegative($amount)])>
                                        {{ $money($amount) }}
                                    </td>
                                @endforeach
                            </tr>
                        @else
                            <tr class="erp-statement__heading">
                                <th scope="row" class="erp-statement__label" colspan="{{ $statement->columnCount() + 1 }}">
                                    {{ $section->title() }}
                                </th>
                            </tr>

                            @forelse ($section->lines as $line)
                                @include('filament.pages.partials.statement-line', [
                                    'line' => $line,
                                    'money' => $money,
                                    'isNegative' => $isNegative,
                                ])
                            @empty
                                <tr>
                                    <td colspan="{{ $statement->columnCount() + 1 }}" class="erp-report__empty">
                                        {{ __('accounting.statements.empty_section') }}
                                    </td>
                                </tr>
                            @endforelse

                            <tr class="erp-statement__total">
                                <th scope="row" class="erp-statement__label">{{ $section->totalLabel() }}</th>
                                @foreach ($section->totals as $amount)
                                    <td @class(['erp-report__num', 'erp-statement__amount--negative' => $isNegative($amount)])>
                                        {{ $money($amount) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endif
                    </tbody>
                @endforeach
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
