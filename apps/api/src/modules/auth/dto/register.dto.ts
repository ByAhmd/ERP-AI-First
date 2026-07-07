import { IsEmail, IsNotEmpty, IsString, MinLength } from 'class-validator';

export class RegisterDto {
  @IsEmail({}, { message: 'Please provide a valid email address' })
  email!: string;

  @IsString()
  @IsNotEmpty({ message: 'Full name is required' })
  fullName!: string;

  @IsString()
  @MinLength(12, { message: 'Password must be at least 12 characters long' })
  password!: string;

  @IsString()
  @IsNotEmpty({ message: 'Please confirm your password' })
  confirmPassword!: string;
}
