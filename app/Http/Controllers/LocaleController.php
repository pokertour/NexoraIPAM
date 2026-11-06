<?php

namespace App\Http\Controllers;

use App\Http\Middleware\BrowserLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(BrowserLocale::SUPPORTED)],
        ]);

        Cookie::queue(Cookie::forever(BrowserLocale::COOKIE, $data['locale']));

        $path = $request->input('return_to');
        if (! is_string($path) || ! preg_match('~\A/(?!/)[^\x00-\x20\x5c\x7f]*\z~', $path)) {
            return redirect()->route('dashboard');
        }

        return new RedirectResponse($path, 303);
    }
}
