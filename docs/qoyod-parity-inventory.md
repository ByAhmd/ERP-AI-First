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
