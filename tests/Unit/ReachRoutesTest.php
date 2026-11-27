<?php

require_once __DIR__.'/../../resources/review/skill/reach-routes.php';

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/reach-routes-'.bin2hex(random_bytes(4));
    $this->put = function (string $path, string $text): void {
        @mkdir(dirname("{$this->dir}/{$path}"), 0777, true);
        file_put_contents("{$this->dir}/{$path}", $text);
    };
});

afterEach(function () {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($this->dir);
});

/**
 * The routes by "METHODS uri action", each with its middleware.
 *
 * @return array<string, list<string>>
 */
function routesOf(string $root): array
{
    $routes = [];

    foreach (reach_routes($root) as $route) {
        $routes["{$route['methods']} {$route['uri']} {$route['action']}"] = $route['middleware'];
    }

    return $routes;
}

it('reads the routes the files bootstrap/app.php loads, with their groups\' middleware, prefixes and aliases', function () {
    ($this->put)('bootstrap/app.php', <<<'PHP'
        <?php

        use App\Http\Middleware\AuthenticateClub;
        use Illuminate\Foundation\Application;
        use Illuminate\Support\Facades\Route;

        return Application::configure(basePath: dirname(__DIR__))
            ->withRouting(
                web: __DIR__.'/../routes/web.php',
                api: __DIR__.'/../routes/api.php',
                then: function () {
                    Route::middleware('web')->prefix('iframe')->group(base_path('routes/iframe.php'));
                },
            )
            ->withMiddleware(function ($middleware) {
                $middleware->alias(['auth.club' => AuthenticateClub::class]);
            })
            ->create();
        PHP);
    ($this->put)('routes/web.php', <<<'PHP'
        <?php

        use App\Http\Controllers\GroupController;
        use App\Http\Controllers\Admin\ReportController;
        use Illuminate\Support\Facades\Route;

        $club = ['auth.club', 'can:manage'];

        Route::middleware($club)->group(function () {
            Route::resource('groups', GroupController::class)->only(['store', 'update']);
            Route::put('groups/{group}/members', [GroupController::class, 'members'])->withoutMiddleware('can:manage');

            Route::controller(GroupController::class)->prefix('groups')->group(function () {
                Route::post('{group}/close', 'close');
            });
        });

        Route::group(['namespace' => 'App\Http\Controllers\Admin', 'prefix' => 'admin', 'middleware' => 'auth'], function () {
            Route::get('reports', 'ReportController@index');
            Route::get('stats', 'StatsController');
            Route::get('exports', ['uses' => 'ReportController@export', 'as' => 'reports.export']);
            Route::get('quotes', '\App\Http\Controllers\Sign\QuoteController@create');
        });

        require __DIR__.'/extra.php';
        PHP);
    ($this->put)('routes/extra.php', "<?php\n\nRoute::get('health', \\App\\Http\\Controllers\\HealthController::class);\nRoute::get('closure', function () { return 'ok'; });\n");
    ($this->put)('routes/api.php', "<?php\n\nuse App\\Http\\Controllers\\Api\\LessonController;\n\nRoute::post('lessons/{lesson}/book', [LessonController::class, 'book'])->middleware('auth:sanctum');\n");
    ($this->put)('routes/iframe.php', "<?php\n\nuse App\\Http\\Controllers\\SignupController;\n\nRoute::post('signup/{club}', [SignupController::class, 'store']);\n");

    expect(routesOf($this->dir))->toBe([
        'POST /groups app\http\controllers\groupcontroller::store' => ['web', 'App\Http\Middleware\AuthenticateClub', 'can:manage'],
        'PUT|PATCH /groups/{id} app\http\controllers\groupcontroller::update' => ['web', 'App\Http\Middleware\AuthenticateClub', 'can:manage'],
        'PUT /groups/{group}/members app\http\controllers\groupcontroller::members' => ['web', 'App\Http\Middleware\AuthenticateClub'],
        'POST /groups/{group}/close app\http\controllers\groupcontroller::close' => ['web', 'App\Http\Middleware\AuthenticateClub', 'can:manage'],
        'GET|HEAD /admin/reports app\http\controllers\admin\reportcontroller::index' => ['web', 'auth'],
        'GET|HEAD /admin/stats app\http\controllers\admin\statscontroller::__invoke' => ['web', 'auth'],
        'GET|HEAD /admin/exports app\http\controllers\admin\reportcontroller::export' => ['web', 'auth'],
        'GET|HEAD /admin/quotes app\http\controllers\sign\quotecontroller::create' => ['web', 'auth'],
        'GET|HEAD /health app\http\controllers\healthcontroller::__invoke' => ['web'],
        'POST /api/lessons/{lesson}/book app\http\controllers\api\lessoncontroller::book' => ['api', 'auth:sanctum'],
        'POST /iframe/signup/{club} app\http\controllers\signupcontroller::store' => ['web'],
    ]);
});

it('adds the middleware a controller sets itself, for the methods it names', function () {
    ($this->put)('composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    ($this->put)('routes/web.php', <<<'PHP'
        <?php

        use App\Http\Controllers\AuthController;
        use App\Http\Controllers\PostController;

        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('posts', [PostController::class, 'store']);
        Route::get('posts', [PostController::class, 'index']);
        PHP);
    ($this->put)('app/Http/Controllers/CrudController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass CrudController\n{\n    public function __construct()\n    {\n        \$this->middleware(\"can:{\$this->ability}.create\")->only('create', 'store');\n    }\n}\n");
    ($this->put)('app/Http/Controllers/PostController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass PostController extends CrudController\n{\n}\n");
    ($this->put)('app/Http/Controllers/AuthController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use Illuminate\Routing\Controllers\HasMiddleware;
        use Illuminate\Routing\Controllers\Middleware;

        class AuthController implements HasMiddleware
        {
            public static function middleware(): array
            {
                return [
                    new Middleware('auth:api', except: ['login']),
                ];
            }
        }
        PHP);

    expect(routesOf($this->dir))->toBe([
        'POST /login app\http\controllers\authcontroller::login' => ['web'],
        'POST /logout app\http\controllers\authcontroller::logout' => ['web', 'auth:api'],
        // A name built from a property is kept up to it.
        'POST /posts app\http\controllers\postcontroller::store' => ['web', 'can:…'],
        'GET|HEAD /posts app\http\controllers\postcontroller::index' => ['web'],
    ]);
});

it('reads resources() with their options, nested resources, and the actions a bound resource registrar adds', function () {
    ($this->put)('app/Routes/ExportResource.php', <<<'PHP'
        <?php

        namespace App\Routes;

        use Illuminate\Routing\ResourceRegistrar;

        class ExportResource extends ResourceRegistrar
        {
            protected $resourceDefaults = ['index', 'export', 'store'];

            protected function addResourceExport($name, $base, $controller, $options)
            {
                $uri = $this->getResourceUri($name) . '/export' . '/{type}';

                return $this->router->any($uri, $this->getResourceAction($name, $controller, 'export', $options));
            }
        }
        PHP);
    ($this->put)('routes/web.php', <<<'PHP'
        <?php

        use App\Http\Controllers\PhotoController;
        use App\Http\Controllers\TagController;
        use App\Http\Controllers\TaskController;

        Route::resource('photos', PhotoController::class)->only(['export']);
        Route::apiResources(['tags' => TagController::class], ['only' => ['store'], 'middleware' => 'auth']);
        Route::resource('projects.tasks', TaskController::class)->only(['store']);
        PHP);

    // Not bound: Laravel registers its own registrar, which has no export.
    expect(array_keys(routesOf($this->dir)))->toBe([
        'POST /tags app\http\controllers\tagcontroller::store',
        'POST /projects/{project}/tasks app\http\controllers\taskcontroller::store',
    ])->and(routesOf($this->dir)['POST /tags app\http\controllers\tagcontroller::store'])->toBe(['web', 'auth']);

    ($this->put)('app/Providers/RouteBindingServiceProvider.php', "<?php\n\nnamespace App\\Providers;\n\nclass RouteBindingServiceProvider\n{\n    public function boot()\n    {\n        \$registrar = new \\App\\Routes\\ExportResource(\$this->app['router']);\n        \$this->app->bind('Illuminate\\Routing\\ResourceRegistrar', fn () => \$registrar);\n    }\n}\n");

    expect(array_keys(routesOf($this->dir)))->toContain('ANY /photos/export/{type} app\http\controllers\photocontroller::export');
});

it('takes the whole middleware stack as Laravel gathers it, and keeps what it cannot read as unknown', function () {
    ($this->put)('composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
    ($this->put)('app/Http/Kernel.php', <<<'PHP'
        <?php

        namespace App\Http;

        class Kernel
        {
            protected $middlewareGroups = [
                'members' => ['throttle:60,1', \App\Http\Middleware\AuthenticateMember::class],
            ];
        }
        PHP);
    ($this->put)('routes/web.php', <<<'PHP'
        <?php

        use App\Http\Controllers\AccountController;
        use App\Http\Controllers\OpenController;
        use App\Http\Controllers\PageController;

        Route::middleware('members')->group(function () {
            Route::get('account', [AccountController::class, 'show']);
        });

        Route::post('account', [AccountController::class, 'store']);

        Route::withoutMiddleware('auth')->group(function () {
            Route::get('open', [OpenController::class, 'show']);
        });

        Route::middleware(config('routes.middleware'))->group(function () {
            Route::get('configured', [AccountController::class, 'edit']);
        });

        Route::post('photos', ['uses' => 'App\Http\Controllers\PageController@store', 'middleware' => 'auth']);

        Route::namespace('App\Http\Controllers')->group(function () {
            Route::get('stats', 'App\Http\Controllers\StatsController');
        });

        Route::get('pages', [PageController::class, 'index']);
        PHP);
    // A controller's auth, which the group excludes; a commented-out one; and a list of them.
    ($this->put)('app/Http/Controllers/OpenController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass OpenController\n{\n    public function __construct()\n    {\n        \$this->middleware('auth');\n    }\n}\n");
    ($this->put)('app/Http/Controllers/BaseController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use Illuminate\Routing\Controllers\Middleware;

        class BaseController
        {
            public function __construct()
            {
                $this->middleware('auth');
            }

            public static function middleware(): array
            {
                return [new Middleware(['throttle:60,1', 'auth:api'], only: ['store'])];
            }
        }
        PHP);
    // Overrides both: no constructor call to the parent's, and its own middleware().
    ($this->put)('app/Http/Controllers/PageController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        class PageController extends BaseController
        {
            public function __construct()
            {
                // $this->middleware('auth');
            }

            public static function middleware(): array
            {
                return [];
            }
        }
        PHP);
    ($this->put)('app/Http/Controllers/AccountController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass AccountController extends BaseController\n{\n}\n");

    expect(routesOf($this->dir))->toBe([
        'GET|HEAD /account app\http\controllers\accountcontroller::show' => ['web', 'members', 'throttle:60,1', 'App\Http\Middleware\AuthenticateMember', 'auth'],
        // Every name in the list, for the methods it is limited to.
        'POST /account app\http\controllers\accountcontroller::store' => ['web', 'auth', 'throttle:60,1', 'auth:api'],
        'GET|HEAD /open app\http\controllers\opencontroller::show' => ['web'],
        'GET|HEAD /configured app\http\controllers\accountcontroller::edit' => ['web', '?', 'auth'],
        'POST /photos app\http\controllers\pagecontroller::store' => ['web', 'auth'],
        'GET|HEAD /stats app\http\controllers\statscontroller::__invoke' => ['web'],
        'GET|HEAD /pages app\http\controllers\pagecontroller::index' => ['web'],
    ]);
});
