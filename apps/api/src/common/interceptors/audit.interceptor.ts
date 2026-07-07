import { CallHandler, ExecutionContext, Injectable, NestInterceptor } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { Observable } from 'rxjs';
import { tap } from 'rxjs/operators';
import { AuditLogsService } from '../../modules/audit-logs/audit-logs.service';
import { RequestUser } from '../decorators/current-user.decorator';
import { AUDITABLE_KEY, AuditableOptions } from '../decorators/auditable.decorator';

@Injectable()
export class AuditInterceptor implements NestInterceptor {
  constructor(
    private readonly reflector: Reflector,
    private readonly auditLogsService: AuditLogsService,
  ) {}

  intercept(context: ExecutionContext, next: CallHandler): Observable<any> {
    const auditableOptions = this.reflector.get<AuditableOptions>(
      AUDITABLE_KEY,
      context.getHandler(),
    );

    if (!auditableOptions) {
      return next.handle();
    }

    const request = context.switchToHttp().getRequest();
    const user = request.user as RequestUser | undefined;
    const ipAddress = request.ip;
    const userAgent = request.headers['user-agent'];
    const tenantId = user?.tenantId;

    return next.handle().pipe(
      tap((response) => {
        // If the response is an object with an 'id', we assume that's the entity ID.
        // Otherwise, if the request has a param 'id', use that.
        const entityId = response?.id || request.params?.id || null;

        // Fire and forget the audit log creation
        this.auditLogsService.create({
          tenantId,
          actorUserId: user?.id,
          action: auditableOptions.action,
          entityType: auditableOptions.entityType,
          entityId,
          ipAddress,
          userAgent,
          // Store minimal request body as metadata, ensuring we exclude passwords
          metadata: this.sanitizeMetadata(request.body),
        }).catch(err => {
          // Log locally if audit fails, but don't crash the request
          console.error('Failed to create audit log', err);
        });
      }),
    );
  }

  private sanitizeMetadata(body: any): any {
    if (!body) return undefined;
    const sanitized = { ...body };
    const sensitiveKeys = ['password', 'confirmPassword', 'secret', 'token', 'refreshToken'];
    
    for (const key of sensitiveKeys) {
      if (key in sanitized) {
        sanitized[key] = '[REDACTED]';
      }
    }
    return sanitized;
  }
}
