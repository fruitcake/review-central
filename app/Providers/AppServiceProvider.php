<?php

namespace App\Providers;

use App\Filament\LocalTime;
use App\Http\Responses\LoginResponse;
use App\Services\Currency;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Support\Facades\FilamentTimezone;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        // Kept for one request or one job: a worker that runs for days sees a change.
        $this->app->scoped(PlatformSettings::class);

        // Reads the rates once for a request or a job.
        $this->app->scoped(Currency::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Filament's dates and times in the zone of whoever reads them: the tenant
        // whose panel is open, the platform's in the admin panel.
        FilamentTimezone::set(fn (): string => LocalTime::zone());

        // The name an administrator gave the installation, also in the mail and the
        // page titles that read it from the configuration. Again for every job, as a
        // worker outlives a change of name.
        $name = function (): void {
            if (is_string($name = app(PlatformSettings::class)->get(PlatformSettings::APP_NAME)) && trim($name) !== '') {
                config(['app.name' => trim($name)]);
            }
        };
        $this->app->booted($name);
        Queue::before($name);

        // Cloudflare and the apps at Google, Asana, Atlassian and GitHub as they were
        // set up under Settings, where the environment leaves them open: the
        // environment's own holds. Again for every job, as a worker outlives a deploy.
        $settings = fn () => app(PlatformSettings::class)->apply();
        $this->app->booted($settings);
        Queue::before($settings);

        // A streamed answer is otherwise given up on after a minute without a byte,
        // and a model thinks for longer than that before it writes. Only a streamed
        // request knows this option.
        Http::globalOptions(['read_timeout' => (int) config('review.diff.timeout')]);

        // A request that carries an Anthropic key may go to a gateway the tenant
        // named. That address was checked to be public; where it redirects to was
        // not, so its redirect is not followed. The AI SDK's own client has no
        // setting for this, and this middleware runs inside Guzzle's own for
        // redirects, which has its options by then: so the answer loses the
        // address to go to, and is handed on as the redirect it is.
        Http::globalMiddleware(fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler): mixed {
            $answer = $handler($request, $options);

            return $request->hasHeader('x-api-key') && $answer instanceof PromiseInterface
                ? $answer->then(fn (ResponseInterface $response): ResponseInterface => $response->withoutHeader('Location'))
                : $answer;
        });

        // The OAuth server's keys, read once it signs or checks a token: kept with the
        // settings, so no server of the installation needs a key file of its own.
        $this->app->beforeResolving(AuthorizationServer::class, fn () => app(PlatformSettings::class)->applyOAuthKeys());
        $this->app->beforeResolving(ResourceServer::class, fn () => app(PlatformSettings::class)->applyOAuthKeys());

        // A client that asks for no scope, as a registered MCP client may, gets the one
        // the MCP servers take: a token without it would be refused by both.
        Passport::defaultScopes(['mcp:use']);

        // The consent screen an MCP client sends someone to before it gets a token.
        Passport::authorizationView(fn (array $parameters): Response => response()->view('mcp.authorize', $parameters));

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
