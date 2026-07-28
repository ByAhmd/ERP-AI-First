<?php

declare(strict_types=1);

namespace App\Casts;

use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a DECIMAL(19,4) column to a {@see Money} value object.
 *
 * Scale is 4 rather than 2 throughout. Unit prices, exchange-rate conversions and
 * per-line tax all produce fractions below the minor unit, and rounding them at
 * storage time is how ledgers drift. Rounding to the currency's natural scale is
 * done deliberately at posting time, not implicitly on every write.
 *
 * The currency is read from a sibling column so that a single row can carry both
 * document and base-currency amounts:
 *
 *     protected function casts(): array
 *     {
 *         return ['total' => MoneyCast::class.':currency_code'];
 *     }
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * Storage scale. Matches the DECIMAL(19,4) column definition.
     */
    public const SCALE = 4;

    public function __construct(
        private readonly string $currencyColumn = 'currency_code',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::of(
            (string) $value,
            $this->resolveCurrency($model, $attributes),
            new CustomContext(self::SCALE),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof Money) {
            // Guard against a document-currency amount being written into a
            // base-currency column, which would corrupt the figure silently.
            $expected = $this->resolveCurrency($model, $attributes);

            if ($value->getCurrency()->getCurrencyCode() !== $expected) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot store %s into [%s]: the row is denominated in %s.',
                    $value->getCurrency()->getCurrencyCode(),
                    $key,
                    $expected,
                ));
            }

            return [$key => $value->getAmount()->toScale(self::SCALE)->__toString()];
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return [$key => Money::of(
                (string) $value,
                $this->resolveCurrency($model, $attributes),
                new CustomContext(self::SCALE),
            )->getAmount()->toScale(self::SCALE)->__toString()];
        }

        throw new InvalidArgumentException(sprintf(
            'Attribute [%s] expects a Money instance or a numeric value, %s given.',
            $key,
            get_debug_type($value),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveCurrency(Model $model, array $attributes): string
    {
        $currency = $attributes[$this->currencyColumn] ?? $model->getAttribute($this->currencyColumn);

        if (filled($currency)) {
            return (string) $currency;
        }

        // Rows that carry no currency column are denominated in the owning
        // company's base currency.
        $company = $model->getAttribute('company');

        return $company?->base_currency ?? config('erp.base_currency');
    }
}
