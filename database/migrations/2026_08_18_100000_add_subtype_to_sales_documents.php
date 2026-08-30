<?php

declare(strict_types=1);

use App\Enums\InvoiceSubtype;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a document is a standard or a simplified tax invoice.
 *
 * ZATCA's KSA-2 subtype, and a Phase 1 requirement the invoice shipped
 * without: the printed document must say which of the two it is. Stored as
 * ZATCA's own two-digit code.
 *
 * NOT NULL on both tables — a nullable compliance column is a column that is
 * always null. Existing rows are backfilled by the same rule the form
 * defaults by: a VAT-registered customer gets a standard invoice, an
 * unregistered one a simplified one, and a credit note follows the invoice it
 * credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->string('subtype', 2)
                ->default(InvoiceSubtype::Standard->value)
                ->after('status');
        });

        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->string('subtype', 2)
                ->default(InvoiceSubtype::Standard->value)
                ->after('status');
        });

        // Backfill by the defaulting rule rather than leaving every historical
        // row claiming to be standard.
        DB::statement(<<<'SQL'
            UPDATE sales_invoices si
            JOIN contacts c ON c.id = si.contact_id
            SET si.subtype = '02'
            WHERE c.tax_number IS NULL OR c.tax_number = ''
        SQL);

        DB::statement(<<<'SQL'
            UPDATE sales_credit_notes cn
            JOIN sales_invoices si ON si.id = cn.parent_id
            SET cn.subtype = si.subtype
        SQL);

        DB::statement(<<<'SQL'
            UPDATE sales_credit_notes cn
            JOIN contacts c ON c.id = cn.contact_id
            SET cn.subtype = '02'
            WHERE cn.parent_id IS NULL
              AND (c.tax_number IS NULL OR c.tax_number = '')
        SQL);
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn('subtype');
        });

        Schema::table('sales_credit_notes', function (Blueprint $table): void {
            $table->dropColumn('subtype');
        });
    }
};
