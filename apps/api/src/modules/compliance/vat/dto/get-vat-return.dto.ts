import { ApiProperty } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import { IsDate, IsNotEmpty } from 'class-validator';

export class GetVatReturnDto {
  @ApiProperty({
    description: 'Start date for the VAT return period',
    example: '2026-01-01T00:00:00Z',
  })
  @IsNotEmpty()
  @IsDate()
  @Type(() => Date)
  startDate: Date;

  @ApiProperty({
    description: 'End date for the VAT return period',
    example: '2026-03-31T23:59:59Z',
  })
  @IsNotEmpty()
  @IsDate()
  @Type(() => Date)
  endDate: Date;
}
