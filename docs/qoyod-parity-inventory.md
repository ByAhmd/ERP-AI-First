# Qoyod parity inventory

Captured 2026-08-04 from a live Qoyod tenant (`YoloER`, #340796) using the
Claude in Chrome extension against the signed-in account. Read-only: navigation
and menu expansion only, nothing created, edited or deleted.

The tenant carries no transactional data, so this records **structure** — the
feature surface, the report catalogue, and the shape of the two statements —
not figures.

## Top-level navigation

| Group | Items |
| --- | --- |
| المبيعات (Sales) | العملاء · عروض الأسعار · فواتير المبيعات · الإشعارات الدائنة · سندات العملاء |
| المشتريات (Purchases) | الموردين · أوامر الشراء · فواتير المشتريات · الإشعارات المدينة · فواتير بسيطة · سندات الموردين |
| المنتجات والتكاليف | المنتجات والتكاليف · المواقع · أوامر التصنيع |
| الأصول الثابتة | الأصول الثابتة · الإهلاك · الاستبعادات · الإضافات |
| الرواتب (Payroll) | onboarding only on this tenant |
| المحاسبة (Accounting) | قوالب التأجيل · قيود سهلة · قيود محاسبية يدوية · شجرة الحسابات · الموازنات · المستندات التجارية · المعاملات المتكررة |
| المهام والمشاريع | المشاريع · المهام |
| التقارير | كل التقارير · سجل التقارير |
| الخدمات الاحترافية | paid professional services (VAT filing, bookkeeping, cleanup, setup, training, invoice design, opening-balance entry) |
| الإعدادات | الإعدادات العامة · السنوات المالية · إعدادات الأبعاد · الاشتراك · الربط الالكتروني · العملات الأجنبية · الضرائب · إعدادات الرواتب · المستخدمين · شروط الدفع · الحقول الإضافية · المرفقات · خصائص المنتجات |

Note `الخدمات الاحترافية` is a services storefront, not product functionality —
it is not a parity target.

## Report catalogue

Eight sections, ~44 reports. Items marked 🔒 are gated behind a higher plan on
this tenant but are still Qoyod features.

**التقارير المالية** — قائمة الدخل · الميزانية العمومية · دفتر القيود ·
ميزان المراجعة · كشف الحساب · ملخص الحساب · قائمة التدفقات النقدية ·
دفتر الأستاذ · مقارنة المقدر بالفعلي لقائمة الدخل · تقرير كشف حساب العميل ·
قائمة التغيرات في حقوق الملكية

**التقارير المجمعة** 🔒 — ميزان المراجعة المجمع · قائمة الدخل المجمعة ·
الميزانية العمومية المجمعة · قائمة التدفقات النقدية المجمعة (multi-company
consolidation)

**التقارير التشغيلية** 🔒 — ملخص المبيعات والمشتريات · حسابات العملاء المدينون ·
حسابات الموردين الدائنين · أعمار عروض الأسعار · أعمار ديون العملاء ·
أعمار أوامر الشراء · أعمار ديون الموردين · تقرير مواقع المنتجات ·
تقرير أعمار الديون · تفاصيل الفاتورة وتقرير هامش الربح

**التقارير البيعية** — تقرير مبيعات المنتجات · تقرير مشتريات المنتجات ·
العملاء الجدد · الفواتير الجديدة · حصص مبيعات المنتجات ·
تقرير تكلفة وهوامش المنتج

**التقارير الضريبية** — نموذج الإقرار الضريبي · تقرير فواتير المبيعات الضريبية ·
تقرير فواتير المشتريات الضريبية · تقرير الإشعارات الدائنة الضريبية ·
تقرير الإشعارات المدينة الضريبية · تقرير دفتر اليومية الضريبية

**تقارير الموظفين** — تقرير رواتب الموظفين · تقرير كشف حساب الموظفين

**تقارير الأصول الثابتة** — تقرير سجل الأصول الثابتة

**تقارير أخرى** — تقرير عمليات المستخدمين · تقرير التحليل بالأبعاد ·
تقرير معاملات التأجيل

## The two statements, as Qoyod renders them

### Filter bar

Both carry the same controls, right to left: date picker · `مقارنة بفترة`
(comparison interval) · `فترة` (number of periods) · `المستوى` (account level,
**defaulting to 7**) · `بحث` · `إعادة تعيين`, plus two toggles — `تحليل متقدم`
(advanced analysis, which reveals dimension comparison by customer, supplier,
project, branch, employee, fixed asset) and `فحص` (drill-down into any line).

Qoyod applies filters on an explicit `بحث` press. Ours recompute live.

### Balance sheet

Rendered as one continuous account tree beginning at the type root, with no
separate section headings:

```
1 - الأصول
  11 - أصول متداولة
    1101 - النقد ومايعادله
    1102 - النقدية في البنك
    1104 - مصروفات مقدمة
  12 - أصول غير متداولة
    1201 - عقارات وآلات ومعدات
2 - الالتزامات
  21 - الالتزامات المتداولة
    2109 - مجمع الاستهلاك
  22 - التزامات غير متداولة
3 - حقوق الملكية
  31 - رأس المال
  32 - حقوق ملكية أخرى
  33 - احتياطيات
  34 - الأرباح المبقاة (أو الخسائر)
```

Two things to note against our implementation:

1. **Accumulated depreciation sits under current liabilities (2109).** This is
   not IFRS presentation — IAS 16 deducts it from property, plant and equipment.
   Ours holds it at 1220 as a contra-asset, which is correct, and we should not
   copy Qoyod here.
2. **The period result is not shown separately.** Everything lands in
   `34 - الأرباح المبقاة (أو الخسائر)`. Ours splits it into
   `أرباح مرحّلة من سنوات سابقة` and `نتيجة السنة الحالية`, which is more
   informative and answers the question an owner actually asks.

### Income statement

Three subtotals, in order:

1. `إجمالي الربح` — gross profit
2. `صافي الدخل قبل الفوائد والضريبة والزكاة` — result before interest, tax and zakat
3. `صافي الربح` — net profit

**Closed.** Ours shipped with only the first and third. Expenses now fall into
three bands — cost of sales, operating, then interest, tax and zakat — each
taken from an account role and everything beneath it. The chart gained `5960
الفوائد والضرائب والزكاة` carrying the `InterestTaxAndZakat` role, with
financing charges, income tax and zakat beneath it.

## Easy entries

`قيود سهلة` is a two-step wizard — choose a type, then fill in the data —
described as being for users without accounting experience. Six types:

تحركات أموال · إضافة رأس مال · إهلاك أصل ثابت · سحب المالك · توزيع أرباح ·
محاسبة الرواتب

Each is a named transaction shape that writes a journal entry the user never
sees in debit-and-credit terms. Worth copying closely when we reach it: this is
the feature that lets a business owner use an accounting system without an
accountant.

## Opening balances

Qoyod has **no screen for these at all.** They are absent from the accounting
menu, absent from the easy-entry wizard, and absent from the chart of accounts,
whose columns are اسم الحساب · النوع · طبيعة الحساب · الوصف · الرصيد ·
يمكن الدفع والتحصيل بهذا الحساب · الخيارات — no opening balance field among
them. A migrating company either writes a manual journal entry or buys
`خدمة إدخال الأرصدة الافتتاحية`, which Qoyod sells as a professional service.

Ours is a dedicated screen: every permanent account listed, a running
difference, saved as a draft until committed, with anything that does not
balance carried to the opening balance suspense account rather than refused or
hidden. This is a place we are ahead rather than at parity — and it is the first
thing a company leaving Qoyod has to do.

## Sales documents

Captured from the live tenant's own forms, so the field names below are Qoyod's
rather than a guess at them. The account holds no documents, so list tables do
not render; the forms do, and they carry the model.

### Taxes (`الضرائب`)

Columns: `رقم الضريبة` · `الاسم` · `الرمز` · `النسبة` · `الحساب` · `الخيارات`.
Three are seeded, and the codes are ZATCA's category codes, not decoration:

| # | الاسم | الرمز | النسبة | الحساب |
|---|---|---|---|---|
| 1 | ضريبة القيمة المضافة | `S` | 15.0 % | 2105 ضريبة القيمة المضافة المستحقة |
| 2 | الضريبة الصفرية | `Z` | 0.0 % | 2105 |
| 3 | معفاة من الضريبة | `E` | 0.0 % | 2105 |

A tax carries its own account, so the posting target is configuration rather
than something the invoice decides.

### Customer (`إضافة عميل`)

One `contact` record serves customers and suppliers — both menu items point at
`/tenant/contacts`.

`code` (الرقم المرجعي, required, auto `CUS001`) · `contact_name` (اسم العميل,
required) · `primary_contact_number` · `secondary_contact_number` ·
`primary_email` · `secondary_email` · `organization_name` · `website` ·
`tax_number` (الرقم الضريبي) · `status` (نشط / غير نشط) · `currency_code` ·
`pos` (عميل نقاط بيع) · `government_entity` (العميل جهة حكومية)

Billing address: `billing_address` · `billing_city` · `billing_state` ·
`billing_zip` · `building_number` · `billing_country`. Shipping address carries
the same minus `building_number` — that field exists on billing alone because it
is part of the Saudi national address a tax invoice must show.

Bank: `name` · `account_name` · `country` · `currency` · `iban` ·
`account_number` · `swift_code` · `address`.

Qoyod's own help notes that `government_entity` cannot be changed once set, and
that extra identifiers (commercial registration and similar) appear only after
e-invoicing is switched on in general settings.

### Sales invoice (`إنشاء فاتورة مبيعات`)

Header: `reference` (المرجع, required, auto `INV1`) · `description` ·
`contact_id` (العميل) · `issue_date` (تاريخ الإصدار, required) ·
`tenant_payment_term_id` (شروط الدفع) · `due_date` (تاريخ الاستحقاق, required) ·
`supply_date` (تاريخ التوريد, required) · `total_amount` ·
`terms_and_conditions` · `notes` · `base_rate` · `foreign_rate`

`supply_date` is a ZATCA requirement, and `base_rate`/`foreign_rate` put the
exchange rate on the document — multi-currency is per document, and the customer
carries a default currency.

Line columns, in order:
`#` · `المنتج` · `الوصف` · `الكمية` · `سعر الوحدة` · `شامل؟` · `الخصم` ·
`الاجمالي قبل الضريبة` · `الضريبة %` · `قيمة الضريبة` · `القيمة`

Line fields: `product_id` · `product_description` · `quantity` · `unit_type` ·
`unit_price` · `is_inclusive` · `discount_percentage` · `discount_type` ·
`row_total_no_tax` · `tax_id` · `row_tax` · `line_total`

`is_inclusive` (`شامل؟`) is per line: a price may be entered VAT-inclusive or
VAT-exclusive. This is precisely the case the predecessor system got wrong —
it credited revenue with the tax-inclusive total — so it is the behaviour worth
testing hardest.

An invoice can also carry a payment inline, through
`receipts_attributes[0]`: `reference` · `account_id` · `description` · `date` ·
`fc_amount` · `amount` · `balance_amount` · `inventory_id`.

**Saving has two outcomes, and Qoyod's help states them explicitly:**

- `حفظ وموافقة` — approve. The invoice is final, appears in reports, and affects
  the accounts.
- `حفظ كمسودة` — draft. Stored and editable, and affects neither.

That is the same distinction `JournalPoster` already draws between `draft()` and
`post()`, so the document layer maps onto the ledger without inventing a second
notion of "not yet real".

Header actions on the invoice list: `إضافة إلى عملية التدقيق` · `تصدير` ·
`إنشاء سند عميل` · `استيراد الفواتير` · `إنشاء فاتورة` · `الإشعارات الدائنة` ·
`إدارة السندات`.

**Not confirmed:** the status tabs. The tenant holds no invoices, so the list
renders its empty state and no status filter appears. The dashboard does link to
`/tenant/invoices` under the label `معلقة`, which implies at least a pending
payment state. Qoyod's documented lifecycle is draft versus approved; the
payment states (paid, partly paid, overdue) most likely derive from receipts
rather than being stored. Treated as unconfirmed rather than guessed — worth a
second look on a tenant that has invoices.

### Credit note (`إنشاء إشعار دائن`)

Structurally the invoice, with the same eleven line columns, the same line
fields and the same inline `receipts_attributes` block. It differs in exactly
three ways:

- `parent_id` — the sales invoice being credited, chosen from a list.
- `external_parent_reference` (`مرجع فاتورة المبيعات الأصلية`) — a free-text
  reference, for crediting an invoice that was never in the system. Rendered
  required on the tenant inspected, though that tenant has no invoices for
  `parent_id` to offer, so the two are likely alternatives rather than both
  being mandatory.
- **No `supply_date`.** The invoice requires one; the credit note does not.

Header fields: `reference` · `description` · `contact_id` · `parent_id` ·
`external_parent_reference` · `issue_date` (labelled `التاريخ`, not
`تاريخ الإصدار`) · `tenant_payment_term_id` · `due_date` ·
`terms_and_conditions` · `notes` · `base_rate` · `foreign_rate`.

**Not present:** any reason-for-issuance field. ZATCA requires one on a credit
note for Phase 2 integration, so either Qoyod derives it from `description` or
it is collected elsewhere in the e-invoicing flow.

### Receipts (`سندات العملاء` / `سند قبض`)

The standalone receipt screens are plan-gated on the tenant inspected
(2026-08-18): the list at `/tenant/receipts?contact_type=customer` renders —
with tabs `سندات العملاء` / `سندات الموردين` / `جميع السندات`, an export
button, and `إدارة السندات` at `/tenant/receipts/customer` — but
`/tenant/receipts/new` redirects to the subscription page, so the create form
could not be read.

What their data model looks like is known anyway, because both the invoice and
credit-note forms embed a receipt block (`receipts_attributes[0]`):

`reference` · `account_id` (the cash/bank account the money lands in) ·
`description` · `date` · `fc_amount` · `amount` · `balance_amount` ·
`inventory_id` — plus `tenant_payment_term_id` at header level. So a Qoyod
receipt names an arbitrary account rather than a fixed cash role, carries a
foreign-currency amount alongside the base amount, and can be created inline
while saving an invoice.

**Not confirmed:** the standalone form's full field set, allocation across
several invoices, and the receipt lifecycle. Worth re-reading on a tenant with
the feature unlocked.

## Gaps this inspection opened

Ordered by how much of the ledger they touch. Struck items have since been
built.

- ~~Income statement is missing the pre-interest/tax/zakat subtotal.~~ Done.
- **Cash flow statement** (`قائمة التدفقات النقدية`) — a fourth primary statement
  we have not scoped at all.
- **Statement of changes in equity** (`قائمة التغيرات في حقوق الملكية`).
- **Budget-vs-actual** (`مقارنة المقدر بالفعلي لقائمة الدخل`) — already on the
  Phase 2 list as part of budgets.
- **Aging reports**, receivable/payable summaries, and the sales/product margin
  family — all downstream of business documents, so they belong with that phase.
- **Deferral templates** (`قوالب التأجيل`) and their transaction report — deferred
  revenue and prepaid expense scheduling. Not previously on our list at all.
- **Report history** (`سجل التقارير`) — queued/generated report runs, implying
  long-running reports execute asynchronously. We have Horizon already.


## عروض الأسعار — implemented 2026-08-30

The tenant's quotation screens are plan-gated, so the slice was designed from
Qoyod's knowledge base and its official API docs (Quotes resource) rather than
the live tenant. What shipped: عرض سعر with رقم عرض السعر (own QTE- series),
اسم العميل, تاريخ الإصدار, تاريخ الانتهاء (required), وصف عرض السعر, the
invoice's items table, statuses مسودة / موافق عليه / تم الفوترة / ملغي, and
تحويل لفاتورة from Approved only — one-shot, landing on the pre-filled draft
invoice. No ledger impact at any status (Qoyod verbatim: the quotation report
is "تجاري وتحليلي، وليس محاسبي").

Deliberate deviations, both documented in code:
- The quotation flips to تم الفوترة when the draft invoice is *created* (Qoyod
  flips when the pre-filled form is *saved*); deleting the still-draft invoice
  reverts it to موافق عليه. Race-safety bought, abandonment path kept.
- The converted invoice shows a discreet «من عرض سعر» provenance line; Qoyod
  shows no back-link at all.

Parity gaps tracked, not built: بانتظار الموافقة (needs role-gated approval,
shared with invoices), الموقع/inventory_id (needs the inventory slice),
مرفقات, custom fields, document-level discount (invoice lacks these too),
إرسال by email, PDF/print designer, Excel export, the list's status chart,
and تقرير أعمار عروض الأسعار (now computable from the schema).


## المشتريات — implemented 2026-08-30

The tenant's purchase screens are plan-gated, so the side was designed from
Qoyod's knowledge base and its official API docs (Bills, Debit Notes,
Receipts/Bill Payments, Orders resources — prefix evidence BIL/SBill/DBN/PYT/ORD
from their own sample data). What shipped, in Qoyod's sidebar order:

- **الموردين** — the same contact record as customers behind its own resource,
  VEN series, shared form extracted so the two screens cannot drift.
- **أوامر الشراء** — ORD- series, statuses مسودة/موافق عليه/تمت الفوترة/ملغي,
  متأخرة derived from تاريخ الانتهاء; never posts; one-shot تحويل لفاتورة with
  agreed prices carried verbatim and taxes re-resolved at the bill's date.
- **فواتير المشتريات** — BIL- series; posts DR expense per line account +
  DR ضريبة القيمة المضافة على المشتريات (1150, an asset) / CR الذمم الدائنة
  (2110); per-line expense account defaulted from the product (which gained
  its own حساب المصروف field); رقم فاتورة المورد with a per-supplier unique
  as the duplicate-bill wall; our issue_date drives the ledger while the
  supplier's paper date is preserved beside it; no subtype, no supply date.
- **الإشعارات المدينة** — DBN- series; the exact mirror posting as its own
  entry; anchored lines inherit the billed rate and the billed expense
  account; narrations carry the supplier's invoice number; no reason codes
  or event date (seller-side ZATCA machinery, confirmed absent in Qoyod).
- **فواتير بسيطة** — SB- series, same table as bills split by kind, account
  lines (البيان/القيمة), no due date; visible natively to every payable query.
- **سندات الموردين** — PYT- series; DR payable (allocated) + DR دفعات مقدمة
  للموردين 1170 (unallocated — an asset, newly keyed supplier_advances with a
  backfill for older tenants) / CR the payment account (يمكن الدفع والتحصيل
  gate reused); allocation/unallocation as their own dated entries; payment
  status on bills derived through the three-term BillOutstanding
  (total − debit notes − payment allocations).

Deviations from Qoyod, each documented in code: the dedicated supplier
invoice number column (Qoyod overloads its single reference; duplicates
would double expense/VAT/AP silently), per-line expense snapshots (Qoyod is
per-product; our bills allow product-less lines), and PO conversion flipping
at draft creation with delete-reverts (race-safety, quotation precedent).

Parity gaps tracked, not built: inventory behavior of bills (stocked lines
debiting المخزون and stock movements — blocked on the inventory slice),
supplier refunds and voucher kind=received, debit-note multi-bill allocation
and cash refund, PO partial billing, بانتظار الموافقة approval chains,
multi-currency settlement, document-level discount, simple-bill embedded
payment, attachments/custom fields/projects, numbering-settings UI,
supplier-total override vs BR-CO-17 recompute, self-billed invoices
(KSA-2 flag 7, schema comment only), and تقرير أعمار ديون الموردين (now
computable from the schema).


## تقارير الأعمار — implemented 2026-08-31

Research finding that reshaped the build: Qoyod's four contact-aging reports
(أعمار ديون العملاء، أعمار ديون الموردين، أعمار عروض الأسعار، أعمار أوامر
الشراء) are NOT day-bucket reports — they are as-of snapshots with optional
prior-period comparison columns (مقارنة بـ سنة/ربع/شهر/أسبوع × up to 13
periods, each cell `amount (count)`). The day-bucket layout lives in a
separate, newer unified تقرير أعمار الديون (deferred; its bucket rule is
recorded: days = asOf − COALESCE(due_date, issue_date); 1–30/31–60/61–90/90+).

What shipped, all four under التقارير:

- **أعمار ديون العملاء / أعمار ديون الموردين** — one row per contact, cell =
  Σ date-bounded invoice remainders + count of open invoices, driven by the
  ISSUE date (supplier-explicit in Qoyod's KB; customer side mirrored —
  flagged unverified). The as-of bound lives INSIDE InvoiceOutstanding /
  BillOutstanding (one definition of outstanding, now date-aware), with the
  allocation's effective date as COALESCE(entry_date, receipt/payment_date) —
  an advance received in June but applied in July leaves the invoice open at
  June 30. A reconciliation footer carries what the grid deliberately omits:
  standalone credit/debit notes and unallocated advances, and the drift-guard
  test ties grid + footer to the AR/AP and advances control accounts in the
  trial balance.
- **أعمار عروض الأسعار / أعمار أوامر الشراء** — approved-only whitelists
  (converted documents drop out even though approved_at survives — the
  double-count trap, pinned), full tax-inclusive totals, issue-date driven,
  expired/overdue-but-approved rows stay in.

Deviations, documented in code: face-value aggregation with a
foreign-currency warning instead of Qoyod's base-currency conversion (a
converted figure would no longer tie to the ledger); negative as-of
remainders shown rather than clamped (same reason); the reconciliation
footer itself (Qoyod shows these figures in separate ملخص مستحقات reports).

Tracked gaps: the unified day-bucket تقرير أعمار الديون (summary/details
views, min-amount filter), ملخص مستحقات العملاء/الموردين, Excel/PDF export,
يشمل ضمان حسن التنفيذ retention toggle (no schema concept), per-report
permissions, contact drill-down, fiscal-aligned year columns.


## أعمار الديون والملخصات — implemented 2026-08-31

- **تقرير أعمار الديون** (the unified day-bucket report): customers and
  suppliers together, buckets حالية / 1–30 / 31–60 / 61–90 / أكثر من 90,
  summary view per contact and details view per document with Qoyod's signed
  أيام التأخير; filters نوع الجهة، الجهة، طريقة العرض، الحد الأدنى للمبلغ.
  Bucket basis = due date with issue-date fallback (simple bills carry no due
  date); day 30 in the first bucket, day 31 in the second — stated in one
  place. Consumes the outstanding services' per-document path, so it shares
  the one definition of the remainder.
- **ملخص مستحقات العملاء / الموردين**: open invoices, unapplied standalone
  notes, unused voucher amounts (مبالغ سندات قبض/صرف لم تستخدم), and the net
  per contact — tied in tests to control-minus-advances. Qoyod's صافي حركات
  القيود اليدوية column cannot exist here (journal lines carry no contact) —
  documented gap.

## المخزون — first slice implemented 2026-08-31

Designed from Qoyod's KB + API (tenant plan-gated). What shipped:

- **تتبع الكمية**: per-product «يُخزن» flag, default OFF for all existing
  products (never retro-inventoried), frozen after the first movement.
  Bundles excluded (no BOM exists). Locations ARE branches; every company
  has a default branch (المركز الرئيسي seeded).
- **Costing**: moving weighted average, company-wide per product;
  quantity per branch. Value authoritative, average derived, never computed
  at zero. Running-forward on backdated documents (Qoyod's behavior);
  movements store both document date and application order. Terminal relief
  hands the last unit the exact remaining value — no orphan halalas.
- **Posting**: stocked bill lines → DR المخزون 1140 (the snapshot IS the
  redirect — written at recalculation, account select shows it); invoices
  append DR تكلفة البضاعة المباعة / CR المخزون to their own entry, cost
  resolved AT APPROVAL under the lock; credit notes restock only for سبب
  الإرجاع at current average; debit notes relieve only with إرجاع بضاعة set,
  net-vs-relief difference on تسويات المخزون 5150. Negative stock refused at
  the shipping branch («الكمية غير متوفرة»). Ledger-screen reversal blocked
  for stock-bearing entries.
- **تسويات المخزون document** (ADJ- series): opening balances (DR 1140 /
  CR حساب الرصيد الافتتاحي — Qoyod's أرصدة افتتاحية للمخزون flow) and count
  variances. Deviation: one offset account defaulting to 5150 both ways,
  instead of Qoyod's revenue-for-surplus + expense-for-shortage pair —
  a counting artifact is not income; the account stays selectable.
- **Surfaces**: product list columns الكمية/متوسط التكلفة/القيمة الإجمالية/
  مخزون؟; الموقع on the five document forms; الجرد accounting via the
  adjustment document.

The closing invariant, held by test: after opening + bills + invoices +
both notes + a count, GL 1140 equals Σ product stock values exactly.

Tracked gaps: نقل المخزون (transfers) + per-branch inventory accounts,
full الجرد UX (Excel/barcode/actual-quantity sheets), أوامر التصنيع and
BOM/bundles, تحويل الوحدات (unit conversions), تقرير مواقع المنتجات and
as-of valuation (pure queries on shipped schema), reorder alerts, oversell
setting, per-product movement screen embedded in product view (data model
complete; UI listing deferred), serial/batch/expiry (Qoyod has none —
explicitly not a parity gap).


## نقل المخزون وتقرير المواقع — implemented 2026-08-31

- **نقل المخزون** (TRF- series, in المنتجات والتكاليف): Qoyod's one-step
  إرسال واستقبال — quantities move between branches at the company average
  inside one transaction, refused at the source when short. No journal
  entry: with one inventory account the net is zero (Qoyod's own net when
  locations share the default account). The movement pair records the
  journey at zero value, keeping the value_after audit chain intact.
  Deferred with per-location inventory accounts: the إرسال-only in-transit
  state, حساب النقل المؤقت, per-line المشروع and custom fields.
- **تقرير مواقع المنتجات** (in التقارير): tracked products × branches
  crosstab with totals and Qoyod's single-location filter; zero-filled.
