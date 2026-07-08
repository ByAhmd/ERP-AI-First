import { IsString, IsArray, ValidateNested, IsNumber, IsOptional, IsUUID } from 'class-validator';
import { Type } from 'class-transformer';

export class GeneratePayslipDto {
  @IsUUID()
  employeeProfileId: string;

  @IsNumber()
  otherDeductions?: number;

  @IsOptional()
  @IsNumber()
  bonus?: number;
}

export class CreatePayrollRunDto {
  @IsString()
  periodName: string;

  @IsArray()
  @ValidateNested({ each: true })
  @Type(() => GeneratePayslipDto)
  payslips: GeneratePayslipDto[];
}
