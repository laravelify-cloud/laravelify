<?php

namespace App\Http\Controllers;

use App\Services\Glitchtip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Open GlitchTip already signed in: the panel signs its GlitchTip admin in and gives the browser
 * GlitchTip's cookies (it is on the panel's own address), then sends it to the page asked for.
 * nginx sends every GlitchTip page opened without a GlitchTip session here (scripts/host-apply.sh
 * glitchtip-nginx), so GlitchTip's own sign-in only shows when this sign-in is turned down.
 */
class GlitchtipLoginController extends Controller
{
    public function __invoke(Request $request, Glitchtip $glitchtip): RedirectResponse
    {
        abort_unless($glitchtip->installed(), 404);

        // Only a page of GlitchTip: a relative path, never another site.
        $to = ltrim((string) $request->query('to', ''), '/');
        // Its start page sends a signed-in browser to "/" (the panel, not /glitchtip), and its
        // sign-in page would come straight back here: open the issues instead.
        $to = preg_match('#^[A-Za-z0-9][A-Za-z0-9/_.?=&%-]*$#', $to) === 1 && ! str_contains($to, '..') && ! preg_match('#^login(?:[/?]|$)#', $to)
            ? $to
            : Glitchtip::ORGANIZATION.'/issues';

        try {
            $cookies = $glitchtip->signIn();
        } catch (Throwable) {
            $cookies = null;
        }

        // Turned down (the password was changed in GlitchTip): GlitchTip's own sign-in, which nginx lets through.
        if ($cookies === null) {
            return redirect()->away($glitchtip->url('login?signin=own'));
        }

        $response = redirect()->away($glitchtip->url($to));

        foreach ($cookies as $name => $value) {
            if (in_array($name, ['sessionid', 'csrftoken'], true)) {
                $response->withCookie(new Cookie($name, $value, now()->addWeeks(2), '/', null, $request->isSecure(), $name === 'sessionid', false, Cookie::SAMESITE_LAX));
            }
        }

        return $response;
    }
}
