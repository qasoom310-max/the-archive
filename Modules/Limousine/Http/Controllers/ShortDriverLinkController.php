<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Modules\Limousine\Support\ShortDriverLink;

/**
 * Opens a short driver link: checks its code and sends the driver on to the
 * full signed address, where every usual check still happens.
 */
final class ShortDriverLinkController
{
    public function trip(string $code): RedirectResponse
    {
        $n = ShortDriverLink::decode('d', $code, 3);
        abort_if($n === null, 404);

        return redirect()->to(URL::signedRoute('limousine.driver_trip.show', $this->params($n)));
    }

    public function bills(string $code): RedirectResponse
    {
        $n = ShortDriverLink::decode('b', $code, 4);
        abort_if($n === null, 404);

        return redirect()->to(URL::temporarySignedRoute('limousine.driver_bills.show', Carbon::createFromTimestamp($n[3]), $this->params($n)));
    }

    /**
     * @param  list<int>  $n
     * @return array<string, int>
     */
    private function params(array $n): array
    {
        $params = ['leg' => $n[1], 'driver' => $n[2]];
        if ($n[0] > 0) {
            $params['ws'] = $n[0];
        }

        return $params;
    }
}
