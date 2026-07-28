# Saudi Compliance

This platform is Saudi-first. This document is engineering guidance, **not a
compliance certification**. Nothing here has been reviewed by a Saudi tax
advisor, and no calculation may be sold or filed on that basis until it has been.

## VAT must reach the ledger

The single most important rule in this document.

Tax is posted as its own journal line, to `VAT Output Payable` on sales and
`VAT Input Receivable` on purchases. Revenue is credited **net of tax**. The VAT
return is then computed **from the general ledger**, never from the invoice
table.

The predecessor did the opposite: it credited revenue with the tax-inclusive line
total, wrote no tax line at all, and derived the VAT return by summing invoices.
The consequences were that revenue was overstated by the VAT amount, no VAT
liability appeared on the balance sheet, and the return could not be reconciled
to the trial balance by construction. Any migrated balance from that system is
wrong wherever VAT was involved.

## Document numbering

Invoice and journal numbering must be **gapless per company per series**.
Sequences are allocated from `document_sequences` inside the same transaction as
the document, under `SELECT ... FOR UPDATE`.

Two failure modes to avoid, both present in the predecessor:

- Deriving a number from `COUNT(*) + 1`, which races and collides.
- Allocating the number outside the transaction that creates the document, so a
  failed insert burns a number and leaves a permanent gap.

Reversals use their own series. Sharing the counter puts gaps in the primary
series, which ZATCA does not accept.

## ZATCA e-invoicing

Seller address is stored as **discrete columns** on `companies` — building
number, street, district, city, postal code, additional number, country — not as
a free-text block. UBL 2.1 requires them as separate elements and prose cannot be
reliably parsed back into them later.

Standard (B2B) and simplified (B2C) tax invoices are distinct document types with
different clearance and reporting obligations. They are modelled as such, not as
a display variation.

Implemented in the compliance phase: UBL 2.1 XML, cryptographic stamping, TLV
Base64 QR, invoice hash chaining (PIH), and Fatoora submission. None of it is
implemented ahead of that phase, and none of it is stubbed in a way that could be
mistaken for working.

## Zakat, WHT, GOSI, WPS

- **Zakat** is commonly assessed on the Hijri year. `companies` carries
  `uses_hijri_fiscal_year` for this reason.
- **Withholding tax** is deducted at payment, posted to its own liability
  account, and reported per supplier.
- **GOSI and WPS** figures derive from payroll postings in the ledger, not from
  a parallel calculation.

Each requires specialist review before production use.

## Hijri dates

Umm al-Qura, via PHP's `intl` extension (`islamic-umalqura`) — the official Saudi
civil calendar, maintained by ICU.

Third-party PHP Hijri packages are not used. Most approximate the calendar
arithmetically and drift from the published Umm al-Qura tables; the package
originally proposed for this platform had received no release since 2020.

**Gregorian remains the storage format.** Hijri is a presentation and
period-definition concern. Storing Hijri would make range queries and date
arithmetic needlessly difficult, and the conversion is cheap.

`App\Support\Calendar\HijriDate` provides conversion in both directions, month
lengths (29 or 30 days, non-cyclical, so it must be asked of ICU), and bilingual
formatting.

## Before production

A Saudi accountant or tax advisor must review VAT, Zakat, WHT and payroll
calculations, and ZATCA integration must be validated against the Fatoora
sandbox, before any of it is offered to customers.
