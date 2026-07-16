import React from "react";
import { QRCodeSVG } from "qrcode.react";

interface InvoiceLine {
  id: string;
  description: string;
  quantity: number;
  unitPrice: number | string;
  taxAmount: number | string;
  total: number | string;
}

interface InvoiceProps {
  invoice: {
    invoiceNumber: string;
    issueDate: string;
    dueDate: string;
    subTotal: number | string;
    taxTotal: number | string;
    total: number | string;
    currencyId: string;
    notes?: string;
    zatcaQrCode?: string;
    contact: {
      name: string;
      email?: string;
      taxId?: string; // e.g. VAT number of customer
      address?: string;
    };
    tenant: {
      name: string;
      vatRegistrationNo?: string;
      commercialRegNo?: string;
      address?: string;
    };
    lines: InvoiceLine[];
  };
}

export const ZatcaInvoicePrint: React.FC<InvoiceProps> = ({ invoice }) => {
  // Use a standard format for currency
  const formatMoney = (val: string | number) => 
    new Intl.NumberFormat('en-SA', { style: 'decimal', minimumFractionDigits: 2 }).format(Number(val));

  const issueDateObj = new Date(invoice.issueDate);
  const formattedDate = issueDateObj.toLocaleDateString('en-SA');
  const formattedTime = issueDateObj.toLocaleTimeString('en-SA', { hour12: false });

  return (
    <div className="zatca-print-container bg-white text-black p-8 max-w-4xl mx-auto font-sans" dir="ltr" style={{ color: '#000', backgroundColor: '#fff' }}>
      
      {/* Header Section */}
      <div className="flex justify-between items-start border-b-2 border-gray-800 pb-6 mb-6">
        <div className="flex-1">
          <h1 className="text-3xl font-bold uppercase tracking-wider mb-2">Tax Invoice</h1>
          <h2 className="text-2xl font-bold" dir="rtl" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>فاتورة ضريبية</h2>
        </div>
        
        {/* QR Code in Top Right for ZATCA Standard */}
        <div className="w-32 h-32 flex justify-end items-start">
          {invoice.zatcaQrCode ? (
            <QRCodeSVG value={invoice.zatcaQrCode} size={128} level="M" />
          ) : (
            <div className="w-32 h-32 border-2 border-dashed border-gray-400 flex items-center justify-center text-gray-400 text-xs text-center p-2">
              No ZATCA QR Code Generated
            </div>
          )}
        </div>
      </div>

      {/* Invoice Meta */}
      <div className="grid grid-cols-2 gap-8 mb-8 text-sm">
        <div className="grid grid-cols-2 gap-2">
          <div className="font-bold text-gray-600">Invoice Number:</div>
          <div className="text-right font-mono">{invoice.invoiceNumber}</div>
          
          <div className="font-bold text-gray-600">Issue Date:</div>
          <div className="text-right">{formattedDate}</div>
          
          <div className="font-bold text-gray-600">Issue Time:</div>
          <div className="text-right">{formattedTime}</div>
          
          <div className="font-bold text-gray-600">Date of Supply:</div>
          <div className="text-right">{formattedDate}</div>
        </div>

        <div className="grid grid-cols-2 gap-2 text-right" dir="rtl" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>
          <div className="font-bold text-gray-600">رقم الفاتورة:</div>
          <div className="text-left font-mono" dir="ltr">{invoice.invoiceNumber}</div>
          
          <div className="font-bold text-gray-600">تاريخ الإصدار:</div>
          <div className="text-left" dir="ltr">{formattedDate}</div>
          
          <div className="font-bold text-gray-600">وقت الإصدار:</div>
          <div className="text-left" dir="ltr">{formattedTime}</div>

          <div className="font-bold text-gray-600">تاريخ التوريد:</div>
          <div className="text-left" dir="ltr">{formattedDate}</div>
        </div>
      </div>

      {/* Parties Info */}
      <div className="grid grid-cols-2 gap-8 mb-8">
        {/* Supplier Info */}
        <div className="border border-gray-300 p-4 rounded bg-gray-50">
          <h3 className="font-bold text-lg mb-2 flex justify-between">
            <span>Supplier Details</span>
            <span dir="rtl" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>تفاصيل المورد</span>
          </h3>
          <div className="mb-1 font-bold">{invoice.tenant.name}</div>
          {invoice.tenant.address && <div className="mb-1 text-sm text-gray-700">{invoice.tenant.address}</div>}
          <div className="grid grid-cols-2 gap-1 text-sm mt-3 pt-3 border-t border-gray-200">
            <div className="text-gray-600">VAT Number:</div>
            <div className="text-right font-mono">{invoice.tenant.vatRegistrationNo || "N/A"}</div>
            <div className="text-gray-600">CR Number:</div>
            <div className="text-right font-mono">{invoice.tenant.commercialRegNo || "N/A"}</div>
          </div>
        </div>

        {/* Customer Info */}
        <div className="border border-gray-300 p-4 rounded bg-gray-50">
          <h3 className="font-bold text-lg mb-2 flex justify-between">
            <span>Customer Details</span>
            <span dir="rtl" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>تفاصيل العميل</span>
          </h3>
          <div className="mb-1 font-bold">{invoice.contact.name}</div>
          {invoice.contact.address && <div className="mb-1 text-sm text-gray-700">{invoice.contact.address}</div>}
          <div className="grid grid-cols-2 gap-1 text-sm mt-3 pt-3 border-t border-gray-200">
            <div className="text-gray-600">VAT Number:</div>
            <div className="text-right font-mono">{invoice.contact.taxId || "N/A"}</div>
            {invoice.contact.email && (
               <>
                 <div className="text-gray-600">Email:</div>
                 <div className="text-right">{invoice.contact.email}</div>
               </>
            )}
          </div>
        </div>
      </div>

      {/* Line Items Table */}
      <div className="mb-8 border border-gray-300 rounded overflow-hidden">
        <table className="w-full text-sm text-left">
          <thead className="bg-gray-100 border-b border-gray-300">
            <tr>
              <th className="p-3">
                <div>Description</div>
                <div dir="rtl" className="text-xs text-gray-500 font-normal mt-1" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>الوصف</div>
              </th>
              <th className="p-3 text-right">
                <div>Unit Price</div>
                <div dir="rtl" className="text-xs text-gray-500 font-normal mt-1" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>سعر الوحدة</div>
              </th>
              <th className="p-3 text-right">
                <div>Quantity</div>
                <div dir="rtl" className="text-xs text-gray-500 font-normal mt-1" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>الكمية</div>
              </th>
              <th className="p-3 text-right">
                <div>Taxable Amount</div>
                <div dir="rtl" className="text-xs text-gray-500 font-normal mt-1" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>المبلغ الخاضع للضريبة</div>
              </th>
              <th className="p-3 text-right">
                <div>Tax Amount (15%)</div>
                <div dir="rtl" className="text-xs text-gray-500 font-normal mt-1" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>مبلغ الضريبة</div>
              </th>
              <th className="p-3 text-right font-bold">
                <div>Total (SAR)</div>
                <div dir="rtl" className="text-xs text-gray-500 font-normal mt-1" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>الإجمالي</div>
              </th>
            </tr>
          </thead>
          <tbody>
            {invoice.lines.map((line) => {
              const lineSubTotal = Number(line.quantity) * Number(line.unitPrice);
              return (
                <tr key={line.id} className="border-b border-gray-200 last:border-b-0">
                  <td className="p-3">{line.description}</td>
                  <td className="p-3 text-right font-mono">{formatMoney(line.unitPrice)}</td>
                  <td className="p-3 text-right font-mono">{line.quantity}</td>
                  <td className="p-3 text-right font-mono">{formatMoney(lineSubTotal)}</td>
                  <td className="p-3 text-right font-mono">{formatMoney(line.taxAmount)}</td>
                  <td className="p-3 text-right font-mono font-bold">{formatMoney(line.total)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Totals */}
      <div className="flex justify-end mb-12">
        <div className="w-1/2 border border-gray-300 rounded p-4 bg-gray-50">
          <div className="flex justify-between mb-3 border-b border-gray-200 pb-2">
            <div>
              <div className="font-bold">Total (Excluding VAT)</div>
              <div dir="rtl" className="text-xs text-gray-500" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>الإجمالي (غير شامل ضريبة القيمة المضافة)</div>
            </div>
            <div className="font-mono text-lg">{formatMoney(invoice.subTotal)}</div>
          </div>
          
          <div className="flex justify-between mb-3 border-b border-gray-200 pb-2">
            <div>
              <div className="font-bold">Total VAT (15%)</div>
              <div dir="rtl" className="text-xs text-gray-500" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>إجمالي ضريبة القيمة المضافة</div>
            </div>
            <div className="font-mono text-lg">{formatMoney(invoice.taxTotal)}</div>
          </div>

          <div className="flex justify-between pt-2">
            <div>
              <div className="font-bold text-xl">Total Amount Due</div>
              <div dir="rtl" className="text-sm text-gray-500 font-bold" style={{ fontFamily: 'Noto Kufi Arabic, sans-serif' }}>إجمالي المبلغ المستحق</div>
            </div>
            <div className="font-mono text-2xl font-bold">SAR {formatMoney(invoice.total)}</div>
          </div>
        </div>
      </div>

      {/* Footer Notes */}
      {invoice.notes && (
        <div className="border-t border-gray-300 pt-4 text-sm text-gray-600">
          <div className="font-bold mb-1">Notes / الملاحظات:</div>
          <div>{invoice.notes}</div>
        </div>
      )}
    </div>
  );
};
