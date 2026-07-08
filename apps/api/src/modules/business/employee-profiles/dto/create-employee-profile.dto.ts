import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsNumber, IsOptional, IsString, IsUUID, Min } from 'class-validator';

export class CreateEmployeeProfileDto {
  @ApiProperty()
  @IsUUID()
  contactId: string;

  @ApiPropertyOptional()
  @IsString()
  @IsOptional()
  gosiNumber?: string;

  @ApiProperty()
  @IsNumber()
  @Min(0)
  basicSalary: number;

  @ApiPropertyOptional()
  @IsNumber()
  @Min(0)
  @IsOptional()
  housingAllowance?: number;

  @ApiPropertyOptional()
  @IsNumber()
  @Min(0)
  @IsOptional()
  transportAllowance?: number;
}
