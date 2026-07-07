import { SetMetadata } from '@nestjs/common';

export interface AuditableOptions {
  action: string;
  entityType: string;
}

export const AUDITABLE_KEY = 'auditable';

/**
 * Marks a route as auditable. The AuditInterceptor will catch successful requests
 * to this route and create an AuditLog entry.
 */
export const Auditable = (options: AuditableOptions) => SetMetadata(AUDITABLE_KEY, options);
