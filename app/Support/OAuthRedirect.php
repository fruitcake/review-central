<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Laravel\Passport\Client;

/**
 * Where the consent screen's sign-in goes once someone approves it, told in
 * words they can check. A client registers itself with any name it likes, so
 * the name alone says nothing about who receives the token.
 */
class OAuthRedirect
{
    protected const LOOPBACK = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /**
     * The address the authorization request sends the code to. The OAuth server
     * has checked it against the client's own addresses before the consent
     * screen is shown, and takes the client's only one when the request names none.
     */
    public static function requested(Request $request, Client $client): string
    {
        $requested = $request->query('redirect_uri');

        return is_string($requested) && $requested !== '' ? $requested : (string) ($client->redirect_uris[0] ?? '');
    }

    /**
     * @return array{where: string, local: bool}
     */
    public static function destination(string $uri): array
    {
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        if ($scheme === 'http' && in_array($host, self::LOOPBACK, true)) {
            return ['where' => 'an app on this computer', 'local' => true];
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            return ['where' => $host, 'local' => false];
        }

        return ['where' => "the app on this computer that opens {$scheme}:// links", 'local' => true];
    }
}
