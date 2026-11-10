<?php

namespace App\Http\Controllers;

use App\Livewire\Settings\PanelAddress;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Answers with the short-lived value Settings -> Panel address just made, so that page knows a new
 * domain really reaches this panel and not some other server.
 */
class PanelAddressCheckController
{
    public function __invoke(): Response
    {
        return response((string) Cache::get(PanelAddress::CHECK_KEY, ''), 200, ['Content-Type' => 'text/plain']);
    }
}
