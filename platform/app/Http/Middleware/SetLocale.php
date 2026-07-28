<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the authenticated user's language preference.
 *
 * Locale drives more than wording: Filament reads text direction from the
 * translation catalogue, so this is what flips the entire panel between RTL and
 * LTR. Only locales the platform actually ships are honoured, since an unknown
 * value would silently fall back and leave the layout direction wrong.
 */
final class SetLocale
{
    /**
     * @var list<string>
     */
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        if (! in_array($locale, self::SUPPORTED, strict: true)) {
            $locale = config('app.locale');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
