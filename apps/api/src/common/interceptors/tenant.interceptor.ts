import { CallHandler, ExecutionContext, Injectable, NestInterceptor, UnauthorizedException } from '@nestjs/common';
import { Observable } from 'rxjs';
import { RequestUser } from '../decorators/current-user.decorator';

@Injectable()
export class TenantInterceptor implements NestInterceptor {
  intercept(context: ExecutionContext, next: CallHandler): Observable<any> {
    const request = context.switchToHttp().getRequest();
    const tenantId = request.headers['x-tenant-id'];
    const user = request.user as RequestUser | undefined;

    // If the route requires a tenant (which we might enforce via metadata in the future),
    // and one is provided, validate it.
    if (tenantId && typeof tenantId === 'string') {
      if (!user) {
        throw new UnauthorizedException('Authentication required to access tenant resources');
      }
      
      // In a real scenario, we'd also verify here that the user is a member of this tenant.
      // That requires a DB lookup, which is better done in a guard or service, but we
      // attach it to the request user so it's easily accessible downstream.
      user.tenantId = tenantId;
    }

    return next.handle();
  }
}
