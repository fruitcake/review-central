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
