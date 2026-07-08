import { ApiProperty } from '@nestjs/swagger';
import { Type } from 'class-transformer';
import { IsDate, IsNotEmpty, IsString, IsUUID } from 'class-validator';

export class ProcessPayrollDto {
  @ApiProperty({ description: 'Payroll Period (e.g. 2026-07)' })
  @IsNotEmpty()
  @IsString()
  periodName: string;

  @ApiProperty({ description: 'Date to post the payroll journal entry' })
  @IsNotEmpty()
  @IsDate()
  @Type(() => Date)
  postDate: Date;

  @ApiProperty({ description: 'The expense account ID for Salaries' })
  @IsUUID()
  salaryExpenseAccountId: string;

  @ApiProperty({ description: 'The liability account ID for Salaries Payable' })
  @IsUUID()
  salaryPayableAccountId: string;

  @ApiProperty({ description: 'The expense account ID for Employer GOSI Contributions' })
  @IsUUID()
  gosiExpenseAccountId: string;

  @ApiProperty({ description: 'The liability account ID for GOSI Payable' })
  @IsUUID()
  gosiPayableAccountId: string;
}
