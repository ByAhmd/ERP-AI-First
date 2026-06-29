import Decimal from 'decimal.js';

export function toDecimal(value: string | number | Decimal): Decimal {
  return new Decimal(value);
}
