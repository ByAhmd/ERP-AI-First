export interface CreateAuditLogDto {
  tenantId?: string;
  actorUserId?: string;
  action: string;
  entityType: string;
  entityId?: string;
}
