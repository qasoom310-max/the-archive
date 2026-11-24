<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Support\BillReader;
use Modules\WhatsApp\Models\AssistantConfiguration;

/**
 * Turns a photo of a bill into a draft expense for a person to check.
 *
 * The reader reports what is printed; this class decides what that means for
 * an expense here: which of our three VAT rates the bill carries, what the
 * amount before VAT is when the bill prints only a total, which currency
 * the bill is in, and whether the
 * date and category are ones the form accepts. It never saves anything. The
 * result is a suggestion that somebody confirms on screen.
 */
final class BillScanner
{
    /** Claude takes images up to 5 MB; a phone photo is shrunk well under it. */
    private const MAX_BYTES = 3_500_000;

    private const MAX_EDGE = 2000;

    public const DISK = 'local';

    public const FOLDER = 'limo_expense_bills';

    /** The key the WhatsApp assistant already uses, or '' when none is saved. */
    public static function apiKey(): string
    {
        if (! class_exists(AssistantConfiguration::class) || ! Schema::hasTable('whatsapp_assistant_configuration')) {
            return '';
        }

        return trim((string) AssistantConfiguration::current()->ai_api_key);
    }

    public static function isAvailable(): bool
    {
        return self::apiKey() !== '';
    }

    /**
     * @return array{
     *     readable: bool, date: string, payee: string, category: string, currency: string, net_amount: float,
     *     vat_rate: int, vat_amount: float, total: float, notes: string, check: list<string>
     * } `check` lists what the reader of the screen should look at twice
     */
    public function scan(string $image, string $mime): array
    {
        [$image, $mime] = self::shrinkImage($image, $mime);

        $reader = app()->bound(BillReader::class) ? app(BillReader::class) : new ClaudeBillReader();
        $read = $reader->read(self::apiKey(), AssistantConfiguration::current()->model(), $image, $mime);

        return $this->draft($read);
    }

    /**
     * @param array{readable: bool, date: string, payee: string, category: string, net_amount: float, vat_amount: float, total: float, currency: string, description: string} $read
     *
     * @return array{readable: bool, date: string, payee: string, category: string, currency: string, net_amount: float, vat_rate: int, vat_amount: float, total: float, notes: string, check: list<string>}
     */
    public function draft(array $read): array
    {
        $check = [];
        $net = round(max(0.0, $read['net_amount']), 3);
        $vat = round(max(0.0, $read['vat_amount']), 3);
        $total = round(max(0.0, $read['total']), 3);

        // Fill in whichever of the three the bill did not print.
        if ($net <= 0.0 && $total > 0.0) {
            $net = round($total - $vat, 3);
        }
        if ($vat <= 0.0 && $total > $net && $net > 0.0) {
            $vat = round($total - $net, 3);
        }

        // Which of OUR rates is this? The nearest of none / 10% / 15%, when
        // the bill's own figures land within half a point of one.
        $rate = 0;
        if ($net > 0.0 && $vat > 0.0) {
            $percent = $vat / $net * 100;
            $nearest = 0;
            foreach (LimoExpense::VAT_RATES as $candidate) {
                if (abs($percent - $candidate) < abs($percent - $nearest)) {
                    $nearest = $candidate;
                }
            }
            if (abs($percent - $nearest) <= 0.5) {
                $rate = $nearest;
            } else {
                // A rate we do not offer: keep the money right by entering
                // the full amount paid with no VAT, and say so.
                $check[] = __('The VAT on this bill is not 10% or 15%. The full amount is entered with no VAT; change it if needed.');
                $net = $total > 0.0 ? $total : round($net + $vat, 3);
                $vat = 0.0;
            }
        }

        $date = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $read['date']) === 1) {
            try {
                $parsed = Carbon::createFromFormat('!Y-m-d', $read['date']);
                // A date far from today is more likely misread than real.
                if ($parsed !== null && $parsed->format('Y-m-d') === $read['date'] && $parsed->between(Carbon::today()->subYears(2), Carbon::today()->addDay())) {
                    $date = $read['date'];
                }
            } catch (\Throwable) {
                $date = '';
            }
        }
        if ($date === '') {
            $date = Carbon::today()->toDateString();
            $check[] = __('The date could not be read, so today is entered.');
        }

        $categories = array_column(LimoExpense::categoryOptions(), 'value');
        $category = in_array($read['category'], $categories, true) ? $read['category'] : 'other';

        if ($net <= 0.0) {
            $check[] = __('The amount could not be read. Type it in.');
        }

        // The amounts stay in the bill's own currency here; the screen
        // converts them to BHD at a rate the person can see and correct.
        $currency = strtoupper(trim($read['currency']));
        if (! array_key_exists($currency, LimoExpense::BILL_CURRENCIES)) {
            $currency = 'BHD';
            $check[] = __('The currency could not be read, so BHD is chosen. Change it if the bill is in another currency.');
        } elseif ($currency !== 'BHD') {
            $check[] = __('This bill is in :currency. Check the currency and the rate before saving.', ['currency' => $currency]);
        }

        return [
            'readable' => $read['readable'],
            'date' => $date,
            'payee' => mb_substr(trim($read['payee']), 0, 190),
            'category' => $category,
            'currency' => $currency,
            'net_amount' => $net,
            'vat_rate' => $rate,
            'vat_amount' => round($net * $rate / 100, 3),
            'total' => round($net + $net * $rate / 100, 3),
            'notes' => mb_substr(trim($read['description']), 0, 500),
            'check' => $check,
        ];
    }

    /**
     * A phone photo is several megabytes and far larger than a receipt needs.
     * Shrink it (longest edge 2000 px, JPEG) so it is quick to send and under
     * the size the reader accepts. Left as it is when it is already small or
     * the image library is not there.
     *
     * @return array{0: string, 1: string}
     */
    public static function shrinkImage(string $image, string $mime): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return [$image, $mime];
        }

        $source = @imagecreatefromstring($image);
        if ($source === false) {
            return [$image, $mime];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_EDGE / max(1, max($width, $height)));

        if ($scale >= 1.0 && strlen($image) <= self::MAX_BYTES) {
            imagedestroy($source);

            return [$image, $mime];
        }

        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($w, $h);
        if ($target === false) {
            imagedestroy($source);

            return [$image, $mime];
        }

        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        imagejpeg($target, null, 85);
        $out = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $out !== '' ? [$out, 'image/jpeg'] : [$image, $mime];
    }
}
