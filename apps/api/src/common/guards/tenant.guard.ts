import { CanActivate, ExecutionContext, Injectable } from '@nestjs/common';

@Injectable()
export class TenantGuard implements CanActivate {
  canActivate(_context: ExecutionContext): boolean {
    // TODO: Enforce tenant membership and tenant boundaries for business endpoints.
    return true;
  }
}
