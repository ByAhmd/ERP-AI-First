import { Injectable } from '@nestjs/common';

@Injectable()
export class PermissionsService {
  listPermissions() {
    // TODO: Define permission catalog and seed tenant/platform permissions.
    return { items: [], message: 'Permissions module placeholder is ready.' };
  }
}
