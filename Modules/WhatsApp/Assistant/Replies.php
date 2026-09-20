<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

/**
 * Every fixed message the assistant sends, in English and Arabic.
 *
 * The wording is the brief's appendix, kept verbatim where it exists. These
 * are code-generated rather than written by the AI on purpose: a confirmation
 * or a booking receipt must state exactly what was (or will be) written, and
 * a model paraphrasing it could get a number wrong.
 *
 * The language follows the staff member (see {@see detectLanguage()}), not the
 * ERP's locale — this runs outside any web session.
 */
final class Replies
{
    public static function detectLanguage(string $text, string $fallback = 'en'): string
    {
        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'ar';
        }

        return preg_match('/\p{L}/u', $text) === 1 ? 'en' : $fallback;
    }

    public static function greeting(string $lang): string
    {
        return $lang === 'ar'
            ? 'أهلاً 👋 مساعد ونعان. أرسل تفاصيل الرحلة — الخدمة، السيارة، التاريخ/الوقت، من → إلى — وأعطيك السعر. تقدر تقول: حجز، عرض سعر، فاتورة، رابط دفع.'
            : "Hi 👋 Wanaan assistant. Send the trip — service, car, date/time, from → to — and I'll quote it. You can also say: book, quotation, invoice, payment link.";
    }

    public static function oneMoment(string $lang): string
    {
        return $lang === 'ar' ? 'لحظة…' : 'One moment…';
    }

    public static function unauthorized(string $lang): string
    {
        return $lang === 'ar'
            ? 'هذا الرقم غير مخوّل لاستخدام مساعد ونعان.'
            : "This number isn't authorized to use the Wanaan assistant.";
    }

    public static function noFare(string $lang): string
    {
        return $lang === 'ar'
            ? 'لا يوجد سعر محدد لهذه الرحلة — اضبطه في النظام أو راجع الفريق.'
            : "I don't have a set fare for that trip — set it in the ERP or check with the team.";
    }

    public static function askCustomer(string $lang): string
    {
        return $lang === 'ar'
            ? 'أرسل اسم العميل ورقم هاتفه لتأكيد الحجز.'
            : 'Send the customer name + phone to confirm the booking.';
    }

    /**
     * @param array<string, string> $v  car, service, option, direction, from, to, datetime, amount, customer_name, customer_phone, company_reference
     */
    public static function confirmBooking(string $lang, array $v): string
    {
        $companyLine = self::companyReferenceLine($lang, $v);

        return $lang === 'ar'
            ? "تأكيد الحجز:\n{$v['car']} · {$v['service']} ({$v['option']}){$v['direction']}\n{$v['from']} ← {$v['to']} · {$v['datetime']}\n{$v['customer_name']} · {$v['customer_phone']}\n{$companyLine}السعر: {$v['amount']} · الدفع: أونلاين (Tap)\nاكتب \"نعم\" للحجز، أو أخبرني بأي تعديل."
            : "Confirm booking:\n{$v['car']} · {$v['service']} ({$v['option']}){$v['direction']}\n{$v['from']} → {$v['to']} · {$v['datetime']}\n{$v['customer_name']} · {$v['customer_phone']}\n{$companyLine}Fare: {$v['amount']} · Payment: Online (Tap)\nReply YES to book, or tell me what to change.";
    }

    /**
     * @param array<string, string> $v
     */
    private static function companyReferenceLine(string $lang, array $v): string
    {
        $reference = $v['company_reference'] ?? '';
        if ($reference === '') {
            return '';
        }

        return $lang === 'ar' ? "الرقم المرجعي للشركة: {$reference}\n" : "Company ref: {$reference}\n";
    }

    /**
     * @param array<string, string> $v
     */
    public static function confirmQuotation(string $lang, array $v): string
    {
        return $lang === 'ar'
            ? "إصدار عرض سعر PDF:\n{$v['car']} · {$v['service']} ({$v['option']}){$v['direction']}\n{$v['from']} ← {$v['to']} · {$v['datetime']}\n{$v['customer_name']} · {$v['customer_phone']}\nالسعر: {$v['amount']}\nاكتب \"نعم\" للإصدار، أو أخبرني بأي تعديل."
            : "Prepare quotation PDF:\n{$v['car']} · {$v['service']} ({$v['option']}){$v['direction']}\n{$v['from']} → {$v['to']} · {$v['datetime']}\n{$v['customer_name']} · {$v['customer_phone']}\nFare: {$v['amount']}\nReply YES to prepare it, or tell me what to change.";
    }

    public static function confirmDocument(string $lang, string $kind, string $booking): string
    {
        $what = match ($kind) {
            'invoice' => $lang === 'ar' ? 'الفاتورة' : 'the invoice',
            default => $lang === 'ar' ? 'أمر الخدمة' : 'the service order',
        };

        return $lang === 'ar'
            ? "إرسال {$what} PDF للحجز {$booking}؟\nاكتب \"نعم\" للإرسال."
            : "Send {$what} PDF for booking {$booking}?\nReply YES to send it.";
    }

    public static function confirmPaymentLink(string $lang, string $booking, string $customer, string $amount): string
    {
        return $lang === 'ar'
            ? "إنشاء رابط دفع (Tap) للحجز {$booking} — {$customer} — {$amount}؟\nاكتب \"نعم\" للإنشاء."
            : "Create a Tap payment link for booking {$booking} — {$customer} — {$amount}?\nReply YES to create it.";
    }

    /**
     * @param array<string, string> $v  booking_no, customer_name, customer_phone, car, from, to, datetime, amount, company_reference
     */
    public static function booked(string $lang, array $v): string
    {
        $companyLine = self::companyReferenceLine($lang, $v);

        return $lang === 'ar'
            ? "تم الحجز ✅\nرقم الحجز: {$v['booking_no']}\n{$v['customer_name']} · {$v['customer_phone']}\n{$v['car']} · {$v['from']} ← {$v['to']} · {$v['datetime']}\n{$companyLine}المبلغ: {$v['amount']} · الدفع: أونلاين (Tap)\nالسائق: يحدده قسم التشغيل\nتبي عرض السعر PDF، الفاتورة، أو رابط الدفع؟"
            : "Booked ✅\nBooking: {$v['booking_no']}\n{$v['customer_name']} · {$v['customer_phone']}\n{$v['car']} · {$v['from']} → {$v['to']} · {$v['datetime']}\n{$companyLine}Amount: {$v['amount']} · Payment: Online (Tap)\nDriver: to be assigned by dispatch\nWant the quotation PDF, the invoice, or the payment link?";
    }

    /**
     * @param array<string, string> $v  customer_name, amount, link
     */
    public static function paymentLink(string $lang, array $v): string
    {
        return $lang === 'ar'
            ? "رابط الدفع (Tap) لـ {$v['customer_name']} — {$v['amount']}:\n{$v['link']}\nأرسله للعميل، وسأخبرك عند إتمام الدفع."
            : "Payment link (Tap) for {$v['customer_name']} — {$v['amount']}:\n{$v['link']}\nForward it to the customer. I'll tell you when it's paid.";
    }

    public static function paid(string $lang, string $booking): string
    {
        return $lang === 'ar'
            ? "تم الدفع ✅ الحجز {$booking} مؤكد ومدفوع الآن."
            : "Paid ✅ Booking {$booking} is now confirmed and paid.";
    }

    public static function partPaid(string $lang, string $booking, string $amount, string $balance): string
    {
        return $lang === 'ar'
            ? "تم استلام دفعة ✅ {$amount} للحجز {$booking}. المتبقي: {$balance}."
            : "Payment received ✅ {$amount} on booking {$booking}. Still owed: {$balance}.";
    }

    public static function documentReady(string $lang, string $kind, string $reference): string
    {
        $what = match ($kind) {
            'quotation' => $lang === 'ar' ? 'عرض السعر' : 'Quotation',
            'invoice' => $lang === 'ar' ? 'الفاتورة' : 'Invoice',
            default => $lang === 'ar' ? 'أمر الخدمة' : 'Service order',
        };

        return $lang === 'ar' ? "{$what} {$reference} 📄" : "{$what} {$reference} 📄";
    }

    public static function cancelled(string $lang): string
    {
        return $lang === 'ar' ? 'تم الإلغاء — لم يُنشأ أي شيء.' : 'Cancelled — nothing was created.';
    }

    public static function forbidden(string $lang): string
    {
        return $lang === 'ar'
            ? 'صلاحياتك في النظام لا تسمح بهذا الإجراء.'
            : "Your ERP permissions don't allow that.";
    }

    public static function bookingNotFound(string $lang): string
    {
        return $lang === 'ar' ? 'لم أجد هذا الحجز. أرسل رقم الحجز أو رقم الرحلة.' : "I couldn't find that booking. Send the booking or trip number.";
    }

    public static function nothingOwed(string $lang): string
    {
        return $lang === 'ar' ? 'لا يوجد مبلغ مستحق على هذا الحجز.' : 'Nothing is owed on that booking.';
    }

    public static function paymentLinkFailed(string $lang): string
    {
        return $lang === 'ar'
            ? 'تعذّر إنشاء رابط الدفع. تحقّق من إعدادات بوابة الخدمة وحاول مرة أخرى.'
            : 'Could not create the payment link. Check the Service Portal settings and try again.';
    }

    public static function portalOff(string $lang): string
    {
        return $lang === 'ar'
            ? 'بوابة الدفع غير مفعّلة في هذا النظام.'
            : 'The payment portal is switched off for this database.';
    }

    public static function failed(string $lang): string
    {
        return $lang === 'ar'
            ? 'حدث خطأ ولم يتم تنفيذ الطلب. حاول مرة أخرى أو استخدم النظام مباشرة.'
            : "Something went wrong and nothing was done. Try again, or use the ERP directly.";
    }

    public static function unavailable(string $lang): string
    {
        return $lang === 'ar'
            ? 'المساعد غير متاح الآن. استخدم النظام مباشرة.'
            : "The assistant isn't available right now. Use the ERP directly.";
    }

    public static function unsupportedType(string $lang): string
    {
        return $lang === 'ar' ? 'أرسل رسالة نصية من فضلك.' : 'Please send a text message.';
    }

    /**
     * @param array<string, string> $v  booking, pickup_at, from, to, car — blank = unchanged, left out of the line
     */
    public static function confirmEditBooking(string $lang, array $v): string
    {
        $line = implode(' · ', array_filter(
            [$v['pickup_at'] ?? '', $v['from'] ?? '', $v['to'] ?? '', $v['car'] ?? ''],
            static fn (string $s): bool => $s !== '',
        ));

        return $lang === 'ar'
            ? "تعديل الحجز {$v['booking']}:\n{$line}\nاكتب \"نعم\" للحفظ، أو أخبرني بأي تعديل آخر."
            : "Edit booking {$v['booking']}:\n{$line}\nReply YES to save, or tell me what else to change.";
    }

    public static function bookingEdited(string $lang, string $booking): string
    {
        return $lang === 'ar' ? "تم تحديث الحجز {$booking} ✅" : "Booking {$booking} updated ✅";
    }

    public static function bookingNotEditable(string $lang): string
    {
        return $lang === 'ar'
            ? 'لا يمكن تعديل هذا الحجز (ملغى أو مكتمل).'
            : "This booking can't be edited (cancelled or completed).";
    }
}
