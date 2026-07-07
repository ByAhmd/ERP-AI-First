import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import * as argon2 from 'argon2';
import { EnvConfig } from '../../../config/env.validation';

export interface PasswordStrengthResult {
  isValid: boolean;
  message: string;
}

@Injectable()
export class PasswordService {
  constructor(private readonly configService: ConfigService<EnvConfig, true>) {}

  /**
   * Hashes a plain text password using Argon2id with environment-configured costs.
   */
  async hash(password: string): Promise<string> {
    const memoryCost = this.configService.get('ARGON2_MEMORY_COST', { infer: true });
    const timeCost = this.configService.get('ARGON2_TIME_COST', { infer: true });
    const parallelism = this.configService.get('ARGON2_PARALLELISM', { infer: true });

    return argon2.hash(password, {
      type: argon2.argon2id,
      memoryCost,
      timeCost,
      parallelism,
    });
  }

  /**
   * Verifies a plain text password against an Argon2 hash.
   */
  async verify(hash: string, plainText: string): Promise<boolean> {
    try {
      return await argon2.verify(hash, plainText);
    } catch {
      return false;
    }
  }

  /**
   * Validates a password against the configured policy.
   * Currently uses platform-wide defaults, but built to accept tenant overrides later.
   */
  validateStrength(password: string): PasswordStrengthResult {
    const minLength = this.configService.get('PASSWORD_MIN_LENGTH', { infer: true });
    const requireUpper = this.configService.get('PASSWORD_REQUIRE_UPPERCASE', { infer: true });
    const requireLower = this.configService.get('PASSWORD_REQUIRE_LOWERCASE', { infer: true });
    const requireDigit = this.configService.get('PASSWORD_REQUIRE_DIGIT', { infer: true });
    const requireSpecial = this.configService.get('PASSWORD_REQUIRE_SPECIAL', { infer: true });

    if (password.length < minLength) {
      return { isValid: false, message: `Password must be at least ${minLength} characters long.` };
    }

    if (requireUpper && !/[A-Z]/.test(password)) {
      return { isValid: false, message: 'Password must contain at least one uppercase letter.' };
    }

    if (requireLower && !/[a-z]/.test(password)) {
      return { isValid: false, message: 'Password must contain at least one lowercase letter.' };
    }

    if (requireDigit && !/\d/.test(password)) {
      return { isValid: false, message: 'Password must contain at least one digit.' };
    }

    if (requireSpecial && !/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) {
      return { isValid: false, message: 'Password must contain at least one special character.' };
    }

    return { isValid: true, message: 'Password meets all policy requirements.' };
  }
}
