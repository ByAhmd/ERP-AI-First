import { IsNumber, IsUUID } from 'class-validator';

export class SetPeriodExchangeRateDto {
  @IsUUID()
  currencyId: string;

  @IsUUID()
  accountingPeriodId: string;

  @IsNumber()
  rate: number;
}
