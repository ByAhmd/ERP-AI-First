{{--
    One row of a financial statement, and everything nested beneath it.

    Recursive: a chart of accounts is a tree of arbitrary depth and the reader
    chooses how much of it to see, so the view cannot assume a fixed number of
    levels. Indentation comes from a level class rather than an inline style,
    and steps on the inline start so it mirrors in Arabic on its own.
--}}
@php
    $level = min($line->depth, 6);
@endphp

<tr class="erp-statement__row">
    <th scope="row" class="erp-statement__label erp-statement__label--level-{{ $level }}">
        @if ($line->code)
            <span class="erp-statement__code">{{ $line->code }}</span>
        @endif
        {{ $line->name }}
    </th>

    @foreach ($line->amounts as $columnIndex => $amount)
        @php
            $canDrill = ($drillDown ?? false)
                && $line->isDrillable()
                && bccomp($amount, '0', 4) !== 0;
        @endphp
        <td
            @class([
                'erp-report__num',
                'erp-statement__amount--negative' => $isNegative($amount),
                'erp-statement__amount--drillable' => $canDrill,
            ])
            @if ($canDrill)
                wire:click="openDrill({{ $sectionIndex }}, '{{ $linePath }}', {{ $columnIndex }})"
                role="button"
                tabindex="0"
            @endif
        >
            {{ $money($amount) }}
        </td>
    @endforeach
</tr>

@foreach ($line->children as $childIndex => $child)
    @include('filament.pages.partials.statement-line', [
        'line' => $child,
        'money' => $money,
        'isNegative' => $isNegative,
        'sectionIndex' => $sectionIndex,
        'linePath' => $linePath.'.'.$childIndex,
        'drillDown' => $drillDown ?? false,
    ])
@endforeach
