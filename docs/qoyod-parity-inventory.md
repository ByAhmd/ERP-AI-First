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
