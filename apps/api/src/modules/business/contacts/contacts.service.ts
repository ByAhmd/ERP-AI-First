import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../../../database/prisma.service';
import { CreateContactDto } from './dto/create-contact.dto';
import { UpdateContactDto } from './dto/update-contact.dto';

@Injectable()
export class ContactsService {
  constructor(private prisma: PrismaService) {}

  async create(tenantId: string, dto: CreateContactDto) {
    const { basicSalary, housingAllowance, transportAllowance, gosiNumber, ...contactData } = dto;

    if (dto.type === 'Employee') {
      return this.prisma.$transaction(async (tx) => {
        const contact = await tx.contact.create({
          data: { tenantId, ...contactData },
        });
        
        await tx.employeeProfile.create({
          data: {
            tenantId,
            contactId: contact.id,
            basicSalary: basicSalary || '0',
            housingAllowance: housingAllowance || '0',
            transportAllowance: transportAllowance || '0',
            gosiNumber: gosiNumber || null,
          }
        });
        
        return contact;
      });
    }

    return this.prisma.contact.create({
      data: {
        tenantId,
        ...contactData,
      },
    });
  }

  async findAll(tenantId: string, type?: string) {
    return this.prisma.contact.findMany({
      where: {
        tenantId,
        ...(type ? { type: type as any } : {}),
      },
      orderBy: { name: 'asc' },
    });
  }

  async findOne(tenantId: string, id: string) {
    const contact = await this.prisma.contact.findFirst({
      where: { tenantId, id },
    });
    if (!contact) throw new NotFoundException('Contact not found');
    return contact;
  }

  async update(tenantId: string, id: string, dto: UpdateContactDto) {
    await this.findOne(tenantId, id); // Ensure it exists and belongs to tenant
    return this.prisma.contact.update({
      where: { id },
      data: dto,
    });
  }

  async remove(tenantId: string, id: string) {
    await this.findOne(tenantId, id);
    
    // Check for references before deleting (e.g. invoices, payments, journal lines)
    // For now, let Prisma throw a foreign key constraint error if in use,
    // which is safely caught by the global exception filter.
    return this.prisma.contact.delete({
      where: { id },
    });
  }
}
