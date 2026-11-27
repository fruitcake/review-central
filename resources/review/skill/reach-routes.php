<?php

/**
 * A Laravel application's routes, read from its route files without running them:
 * the review's checkout is the pull request's code, and booting it would run that
 * code beside the AI provider's key. So this reads what php artisan route:list
 * would print, as far as the files say it in the usual ways:
 *
 *   Route::get('/x', [Controller::class, 'store'])->middleware('auth');
 *   Route::middleware(['auth', 'can:edit'])->prefix('admin')->group(function () { ... });
 *   Route::group(['middleware' => 'auth', 'namespace' => 'Admin'], function () { ... });
 *   Route::controller(Controller::class)->group(function () { Route::post('/x', 'store'); });
 *   Route::resource('photos', PhotoController::class)->only(['store']);
 *
 * and the files bootstrap/app.php's withRouting() or a RouteServiceProvider loads,
 * with their middleware group. What it cannot work out (a route in a loop, a name
 * from a variable) it leaves out: a route missing here is one it could not read,
 * never one that is not there.
 *
 * Plain PHP and its tokenizer, for reach.php: no Composer, nothing of the
 * application loaded.
 */

declare(strict_types=1);

/**
 * The routes, each with its methods, URI, middleware (an alias resolved to its
 * class where the application names one, a group to its members, "?" for what
 * could not be read), the middleware as declared, with groups by their names,
 * and the action in lower case as "class::method".
 *
 * @return list<array{methods: string, uri: string, middleware: list<string>, declared: list<string>, action: string}>
 */
function reach_routes(string $root): array
{
    $reader = new ReachRouteReader($root);

    return $reader->read();
}

/** Reads route files by their tokens. */
final class ReachRouteReader
{
    private const VERBS = ['get' => 'GET|HEAD', 'post' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE', 'options' => 'OPTIONS', 'any' => 'ANY'];

    /** The resource actions: method, URI after the resource's, and whether apiResource has it. */
    private const RESOURCE = [
        'index' => ['GET|HEAD', '', true], 'create' => ['GET|HEAD', '/create', false], 'store' => ['POST', '', true],
        'show' => ['GET|HEAD', '/{id}', true], 'edit' => ['GET|HEAD', '/{id}/edit', false],
        'update' => ['PUT|PATCH', '/{id}', true], 'destroy' => ['DELETE', '/{id}', true],
    ];

    /** @var list<array{methods: string, uri: string, middleware: list<string>, action: string}> */
    private array $routes = [];

    /** @var array<string, string> middleware aliases, to the class each names */
    private array $aliases = [];

    /** @var array<string, true> files read, so a file loaded twice is read once per context */
    private array $reading = [];

    /** @var array<string, string> the RouteServiceProvider's namespace property, which a route group can name */
    private array $properties = [];

    /** @var array<string, mixed> what a route file assigns to a variable, such as a list of middleware */
    private array $variables = [];

    /** @var array<string, array{0: string, 1: string, 2: bool, 3: bool}> actions the application's bound ResourceRegistrar adds, such as export: methods, URI after the resource's, false for apiResource, and whether its method adds middleware */
    private array $extraResourceActions = [];

    /** @var array<string, list<string>> middleware groups, by name: the kernel's and bootstrap/app.php's */
    private array $groups = [];

    public function __construct(private readonly string $root) {}

    /**
     * @return list<array{methods: string, uri: string, middleware: list<string>, action: string}>
     */
    public function read(): array
    {
        $this->readAliases();
        $this->readResourceRegistrar();
        $loaded = $this->routes;
        $bootstrap = "{$this->root}/bootstrap/app.php";

        if (is_file($bootstrap)) {
            $this->readWithRouting($bootstrap);
        }

        foreach (glob("{$this->root}/app/Providers/*.php") ?: [] as $provider) {
            $source = (string) file_get_contents($provider);

            if (! str_contains($source, 'Route::')) {
                continue;
            }

            $this->properties = preg_match('/\$namespace\s*=\s*([\'"][^\'"]+[\'"])/', $source, $namespace) === 1 ? ['namespace' => reach_routes_string($namespace[1])] : [];
            $this->evaluate($this->tokens($source), $this->context(), $provider);
            $this->properties = [];
        }

        // Neither says which files: the framework's defaults.
        if ($this->routes === $loaded && $this->reading === []) {
            $this->load("{$this->root}/routes/web.php", ['middleware' => ['web']] + $this->context());
            $this->load("{$this->root}/routes/api.php", ['middleware' => ['api'], 'prefix' => 'api'] + $this->context());
        }

        // The whole stack, as Laravel gathers it: the route's and its groups', the
        // controller's own, the groups' members; then what the route and its groups exclude.
        foreach ($this->routes as $index => $route) {
            [$class, $method] = explode('::', $route['action'], 2);
            $middleware = $route['middleware'];

            foreach ($this->controllerMiddleware($class) as [$name, $only, $except]) {
                if (($only === null || in_array($method, $only, true)) && ! in_array($method, $except, true)) {
                    $middleware[] = $this->named($name);
                }
            }

            $without = $this->expand($route['without']);
            $this->routes[$index]['middleware'] = array_values(array_diff(array_unique($this->expand($middleware)), $without));
            // As declared, for the reader: the groups by their names.
            $this->routes[$index]['declared'] = array_values(array_diff(array_unique($middleware), $without));
            unset($this->routes[$index]['without']);
        }

        return $this->routes;
    }

    /**
     * Middleware with each group it names replaced by the group's members.
     *
     * @param  list<string>  $middleware
     * @return list<string>
     */
    private function expand(array $middleware, int $depth = 0): array
    {
        $expanded = [];

        foreach ($middleware as $name) {
            if (isset($this->groups[$name]) && $depth < 5) {
                array_push($expanded, $name, ...$this->expand(array_map($this->named(...), $this->groups[$name]), $depth + 1));
            } else {
                $expanded[] = $name;
            }
        }

        return $expanded;
    }

    /**
     * The middleware a controller sets itself, and what it inherits: each with the
     * methods it is limited to (null for all), those it leaves out, and whether a
     * constructor or HasMiddleware sets it. A parent's counts as PHP runs it: its
     * constructor's where the class has none or calls parent::__construct(), its
     * middleware() where the class does not override it. A name built from a
     * variable is kept up to it, as can:….
     *
     * @return list<array{0: string, 1: list<string>|null, 2: list<string>, 3: string}>
     */
    private function controllerMiddleware(string $class, int $depth = 0): array
    {
        static $known = [];

        if (isset($known[$class])) {
            return $known[$class];
        }

        $known[$class] = [];
        $file = $this->fileOf($class);

        if ($file === null || $depth > 5) {
            return [];
        }

        // Without comments: a registration commented out registers nothing. Only a file
        // that mentions middleware at all needs it, and tokenizing every one is slow.
        $source = (string) file_get_contents($file);
        $source = stripos($source, 'middleware') !== false ? reach_routes_code($source) : $source;
        $found = [];
        $methods = fn (?string $list): array => $list === null ? [] : array_map(strtolower(...), preg_match_all('/[\'"](\w+)[\'"]/', $list, $names) > 0 ? $names[1] : []);

        if (preg_match_all('/\$this\s*->\s*middleware\s*\((.*?)\)\s*(?:->\s*(only|except)\s*\((.*?)\))?\s*;/s', $source, $calls, PREG_SET_ORDER) > 0) {
            foreach ($calls as $call) {
                $only = ($call[2] ?? '') === 'only' ? $methods($call[3]) : null;
                $except = ($call[2] ?? '') === 'except' ? $methods($call[3]) : [];
                $names = $call[1];

                // The older form: $this->middleware('auth', ['except' => ['login']]).
                if (preg_match('/,\s*(\[\s*[\'"](?:only|except)[\'"].*)$/s', $call[1], $options) === 1) {
                    $names = substr($call[1], 0, -strlen($options[0]));
                    $only = preg_match('/[\'"]only[\'"]\s*=>\s*(\[[^\]]*\]|[\'"]\w+[\'"])/', $options[1], $limit) === 1 ? $methods($limit[1]) : $only;
                    $except = preg_match('/[\'"]except[\'"]\s*=>\s*(\[[^\]]*\]|[\'"]\w+[\'"])/', $options[1], $limit) === 1 ? $methods($limit[1]) : $except;
                }

                foreach (reach_routes_literals($names) ?: ['?'] as $name) {
                    $found[] = [$name, $only, $except, 'constructor'];
                }
            }
        }

        // HasMiddleware: static function middleware() returns names and new Middleware(name or [names], only:, except:).
        $ownMiddleware = preg_match('/static\s+function\s+middleware\s*\(\s*\)[^{]*\{(.*?)\n\s{4}\}/s', $source, $body) === 1;

        if ($ownMiddleware) {
            if (preg_match_all('/new\s+\\\\?(?:[\w\\\\]*\\\\)?Middleware\s*\((.*?)\)\s*(?:->\s*(only|except)\s*\((.*?)\))?\s*[,\]]/s', $body[1], $objects, PREG_SET_ORDER) > 0) {
                foreach ($objects as $object) {
                    $only = preg_match('/only\s*:\s*(\[[^\]]*\]|[\'"]\w+[\'"])/', $object[1], $limit) === 1 ? $methods($limit[1]) : (($object[2] ?? '') === 'only' ? $methods($object[3]) : null);
                    $except = preg_match('/except\s*:\s*(\[[^\]]*\]|[\'"]\w+[\'"])/', $object[1], $limit) === 1 ? $methods($limit[1]) : (($object[2] ?? '') === 'except' ? $methods($object[3]) : []);
                    $first = trim((string) preg_replace('/,?\s*\b(only|except)\s*:.*$/s', '', $object[1]));
                    // The first argument: one name, or a list of them.
                    $names = str_starts_with($first, '[') ? reach_routes_literals($first) : array_slice(reach_routes_literals($first), 0, 1);

                    foreach ($names ?: ['?'] as $name) {
                        $found[] = [$name, $only, $except, 'middleware'];
                    }
                }

                $body[1] = (string) preg_replace('/new\s+[\w\\\\]*Middleware\s*\(.*?\)(\s*->\s*\w+\s*\(.*?\))?/s', '', $body[1]);
            }

            if (preg_match('/return\s*\[(.*)\]/s', $body[1], $returned) === 1) {
                foreach (reach_routes_literals($returned[1]) as $name) {
                    $found[] = [$name, null, [], 'middleware'];
                }
            }
        }

        [$namespace, $uses] = reach_routes_imports($source);

        if (preg_match('/\bclass\s+\w+\s+extends\s+([\w\\\\]+)/', $source, $parent) === 1) {
            $ownConstructor = preg_match('/function\s+__construct\s*\(/', $source) === 1;
            $callsParent = preg_match('/parent\s*::\s*__construct\s*\(/', $source) === 1;

            foreach ($this->controllerMiddleware(strtolower(reach_routes_resolve($parent[1], $namespace, $uses)), $depth + 1) as $inherited) {
                if (($inherited[3] === 'constructor' && (! $ownConstructor || $callsParent)) || ($inherited[3] === 'middleware' && ! $ownMiddleware)) {
                    $found[] = $inherited;
                }
            }
        }

        return $known[$class] = $found;
    }

    /** A class's file, by the application's PSR-4 autoload; null outside it. */
    private function fileOf(string $class): ?string
    {
        static $prefixes = null;

        if ($prefixes === null) {
            $composer = json_decode((string) @file_get_contents("{$this->root}/composer.json"), true);
            $prefixes = [];

            foreach ((array) ($composer['autoload']['psr-4'] ?? ['App\\' => 'app/']) as $prefix => $directories) {
                foreach ((array) $directories as $directory) {
                    $prefixes[strtolower((string) $prefix)][] = rtrim((string) $directory, '/');
                }
            }
        }

        foreach ($prefixes as $prefix => $directories) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            foreach ($directories as $directory) {
                // On a case-sensitive disk the lower-case name is found part by part.
                $path = "{$this->root}/{$directory}";

                foreach (explode('\\', substr($class, strlen($prefix))) as $part) {
                    $match = null;

                    foreach (@scandir($path) ?: [] as $entry) {
                        if (strtolower($entry) === $part || strtolower($entry) === "{$part}.php") {
                            $match = $entry;

                            break;
                        }
                    }

                    if ($match === null) {
                        continue 2;
                    }

                    $path .= "/{$match}";
                }

                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /** The class a middleware alias names, from the HTTP kernel or bootstrap/app.php. */
    private function readAliases(): void
    {
        $this->readGroups();

        foreach (["{$this->root}/app/Http/Kernel.php", "{$this->root}/bootstrap/app.php"] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $source = (string) file_get_contents($file);
            [$namespace, $uses] = reach_routes_imports($source);

            if (preg_match_all('/([\'"])([\w.\-]+)\1\s*=>\s*(\\\\?[\w\\\\]+)::class/', $source, $found, PREG_SET_ORDER) > 0) {
                foreach ($found as $alias) {
                    $this->aliases[$alias[2]] = reach_routes_resolve($alias[3], $namespace, $uses);
                }
            }
        }
    }

    /**
     * The middleware groups the kernel declares, and those bootstrap/app.php declares
     * or adds to: group('members', [...]), appendToGroup('web', ...), web(append: [...]).
     */
    private function readGroups(): void
    {
        $kernel = "{$this->root}/app/Http/Kernel.php";

        if (is_file($kernel)) {
            $source = reach_routes_code((string) file_get_contents($kernel));
            [$namespace, $uses] = reach_routes_imports($source);

            if (preg_match('/\$middlewareGroups\s*=\s*\[/', $source, $start, PREG_OFFSET_CAPTURE) === 1) {
                $block = reach_routes_bracket($source, $start[0][1] + strlen($start[0][0]) - 1);

                if (preg_match_all('/([\'"])([\w.\-]+)\1\s*=>\s*\[/', $block, $names, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
                    foreach ($names as $name) {
                        $this->groups[$name[2][0]] = $this->classes(reach_routes_bracket($block, $name[0][1] + strlen($name[0][0]) - 1), $namespace, $uses);
                    }
                }
            }
        }

        $bootstrap = "{$this->root}/bootstrap/app.php";

        if (! is_file($bootstrap)) {
            return;
        }

        $source = reach_routes_code((string) file_get_contents($bootstrap));
        [$namespace, $uses] = reach_routes_imports($source);

        if (preg_match_all('/->\s*(group|appendToGroup|prependToGroup)\s*\(\s*([\'"])([\w.\-]+)\2\s*,/', $source, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($calls as $call) {
                $open = $call[0][1] + strlen($call[0][0]) - 1;
                $arguments = reach_routes_bracket($source, (int) strrpos(substr($source, 0, $open + 1), '('));
                $members = $this->classes(substr($arguments, strpos($arguments, ',') + 1), $namespace, $uses);
                $this->groups[$call[3][0]] = $call[1][0] === 'group' ? $members : array_merge($this->groups[$call[3][0]] ?? [], $members);
            }
        }

        if (preg_match_all('/->\s*(web|api)\s*\(/', $source, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($calls as $call) {
                $arguments = reach_routes_bracket($source, $call[0][1] + strlen($call[0][0]) - 1);

                foreach (['append', 'prepend'] as $where) {
                    if (preg_match('/\b'.$where.'\s*:\s*/', $arguments, $at, PREG_OFFSET_CAPTURE) === 1) {
                        $value = substr($arguments, $at[0][1] + strlen($at[0][0]));
                        $value = str_starts_with($value, '[') ? reach_routes_bracket($value, 0) : (string) preg_replace('/,.*$/s', '', $value);
                        $this->groups[$call[1][0]] = array_merge($this->groups[$call[1][0]] ?? [], $this->classes($value, $namespace, $uses));
                    }
                }
            }
        }
    }

    /**
     * The middleware a piece of code lists: names as written, classes in full.
     *
     * @param  array<string, string>  $uses
     * @return list<string>
     */
    private function classes(string $code, string $namespace, array $uses): array
    {
        preg_match_all('/([\'"])([^\'"]*)\1|(\\\\?[\w\\\\]+)::class/', $code, $found, PREG_SET_ORDER);

        return array_map(fn (array $match): string => ($match[3] ?? '') !== '' ? reach_routes_resolve($match[3], $namespace, $uses) : $match[2], $found);
    }

    /**
     * The application's own ResourceRegistrar, where a provider binds one: the actions
     * its resource defaults add, each with the method and URI its addResource method
     * gives it. One it does not bind, or an action whose route its method does not
     * say plainly, adds nothing.
     */
    private function readResourceRegistrar(): void
    {
        $bound = '';

        foreach (glob("{$this->root}/app/Providers/*.php") ?: [] as $provider) {
            $source = (string) file_get_contents($provider);

            if (preg_match('/->\s*(?:bind|singleton)\s*\(\s*(?:[\'"]\\\\?Illuminate\\\\+Routing\\\\+ResourceRegistrar[\'"]|\\\\?(?:Illuminate\\\\Routing\\\\)?ResourceRegistrar::class)/', $source) === 1) {
                $bound .= $source;
            }
        }

        if ($bound === '' || ! is_dir("{$this->root}/app")) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$this->root}/app", FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'ResourceRegistrar')) {
                continue;
            }

            $source = reach_routes_code($source);

            if (preg_match('/\bclass\s+(\w+)/', $source, $class) !== 1 || preg_match('/\b'.$class[1].'\b/', $bound) !== 1
                || preg_match('/\$resourceDefaults\s*=\s*\[([^\]]*)\]/', $source, $defaults) !== 1) {
                continue;
            }

            foreach (array_diff(array_map(strtolower(...), reach_routes_literals($defaults[1])), array_keys(self::RESOURCE)) as $action) {
                if (preg_match('/function\s+addResource'.preg_quote($action, '/').'\s*\((.*?)\n\s{4}\}/si', $source, $method) !== 1
                    || preg_match('/->\s*router\s*->\s*(get|post|put|patch|delete|options|any)\s*\(/i', $method[1], $verb) !== 1) {
                    continue;
                }

                // The URI as getResourceUri() and the strings after it build it; a member route's {base} as {id}.
                $suffix = preg_match('/getResourceUri\s*\([^)]*\)((?:\s*\.\s*(?:[\'"][^\'"]*[\'"]|\$base))*)\s*[;,)]/', $method[1], $uri) === 1
                    ? (string) preg_replace(['/\$base/', '/[\'"\s.]/'], ['id', ''], $uri[1])
                    : null;

                if ($suffix !== null) {
                    // Middleware the method adds itself is not read: the route's stack is then unknown.
                    $this->extraResourceActions[$action] = [self::VERBS[strtolower($verb[1])], $suffix, false, stripos($method[1], 'middleware') !== false];
                }
            }
        }
    }

    /** A resource's URI: photos, or projects/{project}/tasks for projects.tasks. */
    private function resourceUri(string $name): string
    {
        $segments = explode('.', $name);
        $last = array_pop($segments);

        return implode('', array_map(fn (string $segment): string => "{$segment}/{".preg_replace('/s$/', '', str_replace('-', '_', $segment)).'}/', $segments)).$last;
    }

    /** bootstrap/app.php: ->withRouting(web: ..., api: ..., then: function () { ... }). */
    private function readWithRouting(string $file): void
    {
        $tokens = $this->tokens((string) file_get_contents($file));
        $count = count($tokens);

        for ($at = 0; $at < $count; $at++) {
            if (! $this->is($tokens[$at], 'withRouting') || ($tokens[$at + 1] ?? null) !== '(') {
                continue;
            }

            $close = $this->closing($tokens, $at + 1);
            $named = [];

            foreach ($this->arguments($tokens, $at + 1, $close) as [$from, $to]) {
                if (is_array($tokens[$from]) && ($tokens[$from + 1] ?? null) === ':') {
                    $named[strtolower($tokens[$from][1])] = [$from + 2, $to];
                }
            }

            $prefix = isset($named['apiprefix']) ? (string) $this->value($tokens, ...$named['apiprefix'], file: $file) : 'api';

            foreach (['web' => ['web', ''], 'api' => ['api', $prefix]] as $name => [$group, $uriPrefix]) {
                if (isset($named[$name])) {
                    foreach ((array) $this->value($tokens, ...$named[$name], file: $file) as $path) {
                        if (is_string($path)) {
                            $this->load($path, ['middleware' => [$group], 'prefix' => $uriPrefix] + $this->context());
                        }
                    }
                }
            }

            foreach (['then', 'using'] as $name) {
                $closure = isset($named[$name]) ? $this->value($tokens, ...$named[$name], file: $file) : null;

                if (is_array($closure) && isset($closure['closure'])) {
                    $this->evaluate($tokens, $this->context(), $file, ...$closure['closure']);
                }
            }

            return;
        }
    }

    /**
     * @return array{middleware: list<string>, prefix: string, namespace: string, controller: string|null}
     */
    private function context(): array
    {
        return ['middleware' => [], 'without' => [], 'prefix' => '', 'namespace' => '', 'controller' => null];
    }

    /**
     * Reads a route file in a context: the middleware, prefix and namespace of what loads it.
     *
     * @param  array{middleware: list<string>, prefix: string, namespace: string, controller: string|null}  $context
     */
    private function load(string $file, array $context): void
    {
        $key = $file.'|'.json_encode($context);

        if (isset($this->reading[$key]) || ! is_file($file)) {
            return;
        }

        $this->reading[$key] = true;
        $this->evaluate($this->tokens((string) file_get_contents($file)), $context, $file);
    }

    /**
     * Every Route:: chain between two tokens, and the files a require names.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     * @param  array{middleware: list<string>, prefix: string, namespace: string, controller: string|null}  $context
     */
    private function evaluate(array $tokens, array $context, string $file, int $from = 0, ?int $to = null): void
    {
        $to ??= count($tokens);

        for ($at = $from; $at < $to; $at++) {
            $token = $tokens[$at];

            if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                $end = $this->statementEnd($tokens, $at + 1, $to);
                $path = $this->value($tokens, $at + 1, $end, file: $file);

                if (is_string($path)) {
                    $this->load($path, $context);
                }

                $at = $end;

                continue;
            }

            // $middleware = ['auth', ...]; read for the chains after it.
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] !== '$this' && ($tokens[$at + 1] ?? null) === '=') {
                $end = $this->statementEnd($tokens, $at + 2, $to);
                $this->variables[$token[1]] = $this->value($tokens, $at + 2, $end, file: $file);
                $at = $end;

                continue;
            }

            if ($this->isRoute($tokens, $at)) {
                $at = $this->chain($tokens, $at, $context, $file);
            }
        }
    }

    /**
     * One chain from Route::, applied; the token it ends at.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     * @param  array{middleware: list<string>, prefix: string, namespace: string, controller: string|null}  $context
     */
    private function chain(array $tokens, int $at, array $context, string $file): int
    {
        $attributes = ['middleware' => [], 'without' => [], 'prefix' => '', 'namespace' => null, 'controller' => null];
        $pending = [];
        $at += 2;

        while (is_array($tokens[$at] ?? null) && ($tokens[$at + 1] ?? null) === '(') {
            $method = strtolower($tokens[$at][1]);
            $close = $this->closing($tokens, $at + 1);
            $arguments = $this->arguments($tokens, $at + 1, $close);
            $value = fn (int $index): mixed => isset($arguments[$index]) ? $this->value($tokens, ...$arguments[$index], file: $file) : null;

            switch (true) {
                case isset(self::VERBS[$method]) || $method === 'match':
                    $offset = $method === 'match' ? 1 : 0;
                    $methods = $method === 'match' ? implode('|', array_map(strtoupper(...), array_filter((array) $value(0), is_string(...)))) : self::VERBS[$method];
                    $uri = $value($offset);
                    $actionValue = $value($offset + 1);
                    $action = $this->action($actionValue, $context, $attributes);

                    // ['uses' => ..., 'middleware' => 'auth', 'excluded_middleware' => ...] carries its own.
                    $own = is_array($actionValue) ? $this->names($actionValue['middleware'] ?? []) : [];
                    $ownWithout = is_array($actionValue) ? $this->names($actionValue['excluded_middleware'] ?? $actionValue['withoutMiddleware'] ?? []) : [];

                    if (is_string($uri) && $action !== null) {
                        $pending[] = ['methods' => $methods, 'uri' => $uri, 'action' => $action, 'middleware' => $own, 'without' => $ownWithout];
                    }

                    break;

                case in_array($method, ['resource', 'apiresource', 'resources', 'apiresources'], true):
                    // resources(['photos' => PhotoController::class, ...], $options) is resource() for each.
                    $plural = str_ends_with($method, 'resources');
                    $resources = $plural ? (is_array($value(0)) ? $value(0) : []) : [$value(0) => $value(1)];
                    $options = $value($plural ? 1 : 2);
                    $options = is_array($options) ? $options : [];
                    $api = str_starts_with($method, 'api');

                    foreach ($resources as $name => $class) {
                        if (! is_string($name) || ! is_string($class)) {
                            continue;
                        }

                        // The application's own registrar's actions, as its addResource methods say.
                        $actions = self::RESOURCE + ($api ? [] : $this->extraResourceActions);

                        foreach ($actions as $action => $spec) {
                            [$methods, $suffix, $inApi] = $spec;
                            $ownMiddleware = $spec[3] ?? false;
                            $only = isset($options['only']) ? array_map(strtolower(...), array_filter((array) $options['only'], is_string(...))) : null;
                            $except = array_map(strtolower(...), array_filter((array) ($options['except'] ?? []), is_string(...)));

                            if (($api && ! $inApi) || ($only !== null && ! in_array($action, $only, true)) || in_array($action, $except, true)) {
                                continue;
                            }

                            $pending[] = [
                                'methods' => $methods,
                                'uri' => $this->resourceUri($name).$suffix,
                                'action' => strtolower($this->className($class, $context, $attributes)).'::'.$action,
                                'resource' => $action,
                                'middleware' => [...$this->names($options['middleware'] ?? []), ...($ownMiddleware ? ['?'] : [])],
                                'without' => $this->names($options['excluded_middleware'] ?? $options['withoutMiddleware'] ?? []),
                            ];
                        }
                    }

                    break;

                case in_array($method, ['only', 'except'], true):
                    $names = array_map(strtolower(...), array_filter((array) $value(0), is_string(...)));
                    $pending = array_values(array_filter($pending, fn (array $route): bool => ! isset($route['resource']) || in_array($route['resource'], $names, true) === ($method === 'only')));

                    break;

                case $method === 'middleware':
                    $attributes['middleware'] = array_merge($attributes['middleware'], $this->middleware($arguments, $tokens, $file));

                    break;

                case $method === 'withoutmiddleware':
                    $attributes['without'] = array_merge($attributes['without'], $this->middleware($arguments, $tokens, $file));

                    break;

                case $method === 'prefix':
                    $attributes['prefix'] = trim($attributes['prefix'].'/'.(is_string($value(0)) ? $value(0) : '{?}'), '/');

                    break;

                case $method === 'namespace':
                    $attributes['namespace'] = is_string($value(0)) ? $value(0) : null;

                    break;

                case $method === 'controller':
                    $attributes['controller'] = is_string($value(0)) ? $value(0) : null;

                    break;

                case $method === 'group':
                    $inner = $context;

                    // Route::group([...], function () { ... }): its attributes in the array.
                    if (count($arguments) === 2) {
                        $array = $value(0);

                        if (is_array($array)) {
                            $attributes['middleware'] = array_merge($attributes['middleware'], $this->names(array_key_exists('middleware', $array) ? $array['middleware'] : []));
                            $attributes['without'] = array_merge($attributes['without'], $this->names($array['excluded_middleware'] ?? $array['withoutMiddleware'] ?? []));
                            $attributes['prefix'] = trim($attributes['prefix'].'/'.(is_string($array['prefix'] ?? null) ? $array['prefix'] : (isset($array['prefix']) ? '{?}' : '')), '/');
                            $attributes['namespace'] = is_string($array['namespace'] ?? null) ? $array['namespace'] : $attributes['namespace'];
                        }
                    }

                    $inner = $this->inner($context, $attributes);
                    $body = $value(count($arguments) - 1);

                    if (is_array($body) && isset($body['closure'])) {
                        $this->evaluate($tokens, $inner, $file, ...$body['closure']);
                    } else {
                        foreach ((array) $body as $path) {
                            if (is_string($path)) {
                                $this->load($path, $inner);
                            }
                        }
                    }

                    break;
            }

            $at = $close + 1;

            if (($tokens[$at] ?? null) === '->' || (is_array($tokens[$at] ?? null) && $tokens[$at][0] === T_OBJECT_OPERATOR)) {
                $at++;

                continue;
            }

            break;
        }

        foreach ($pending as $route) {
            $inner = $this->inner($context, $attributes);
            $this->routes[] = [
                'methods' => $route['methods'],
                'uri' => '/'.trim($inner['prefix'].'/'.ltrim($route['uri'], '/'), '/'),
                'middleware' => array_merge($inner['middleware'], $route['middleware'] ?? []),
                'without' => array_merge($inner['without'], $route['without'] ?? []),
                'action' => $route['action'],
            ];
        }

        return $at;
    }

    /**
     * A group's context inside: the outer one with the attributes added.
     *
     * @param  array{middleware: list<string>, prefix: string, namespace: string, controller: string|null}  $context
     * @param  array{middleware: list<string>, prefix: string, namespace: string|null, controller: string|null}  $attributes
     * @return array{middleware: list<string>, prefix: string, namespace: string, controller: string|null}
     */
    private function inner(array $context, array $attributes): array
    {
        $namespace = $attributes['namespace'];

        return [
            'middleware' => array_merge($context['middleware'], $attributes['middleware']),
            'without' => array_merge($context['without'] ?? [], $attributes['without'] ?? []),
            'prefix' => trim($context['prefix'].'/'.$attributes['prefix'], '/'),
            // A group's namespace adds to the outer one, unless it starts at the root.
            'namespace' => $namespace === null ? $context['namespace'] : (str_starts_with($namespace, '\\') ? ltrim($namespace, '\\') : trim($context['namespace'].'\\'.$namespace, '\\')),
            'controller' => $attributes['controller'] ?? $context['controller'],
        ];
    }

    /**
     * The action a route names, as "class::method" in lower case; null for a closure or a view.
     *
     * @param  array{middleware: list<string>, prefix: string, namespace: string, controller: string|null}  $context
     * @param  array{middleware: list<string>, prefix: string, namespace: string|null, controller: string|null}  $attributes
     */
    private function action(mixed $action, array $context, array $attributes): ?string
    {
        $controller = ltrim((string) ($attributes['controller'] ?? $context['controller']), "\0");

        // The older form: ['uses' => 'Controller@method', 'as' => 'name'].
        if (is_array($action) && is_string($action['uses'] ?? null)) {
            return $this->action($action['uses'], $context, $attributes);
        }

        if (is_array($action) && isset($action[0], $action[1]) && is_string($action[0]) && is_string($action[1])) {
            return strtolower($this->className($action[0], $context, $attributes).'::'.$action[1]);
        }

        if (! is_string($action)) {
            return null;
        }

        // An invokable class, by ::class.
        if (str_starts_with($action, "\0")) {
            return strtolower(substr($action, 1).'::__invoke');
        }

        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return strtolower($this->className($class, $context, $attributes).'::'.$method);
        }

        // In a controller group, a method's name; else an invokable class.
        if ($controller !== '' && preg_match('/^\w+$/', $action) === 1 && ! str_contains($action, '\\')) {
            return strtolower($controller.'::'.$action);
        }

        // An invokable controller by its name, under the group's namespace.
        return preg_match('/^\\\\?[A-Z][\\w\\\\]*$/', $action) === 1 ? strtolower($this->className($action, $context, $attributes).'::__invoke') : null;
    }

    /**
     * A controller's class in full: as resolved where the file names it with ::class,
     * else under the group's namespace.
     *
     * @param  array{middleware: list<string>, prefix: string, namespace: string, controller: string|null}  $context
     * @param  array{middleware: list<string>, prefix: string, namespace: string|null, controller: string|null}  $attributes
     */
    private function className(string $class, array $context, array $attributes): string
    {
        if (str_starts_with($class, "\0")) {
            return substr($class, 1);
        }

        // A name from the root is not under the group's namespace.
        if (str_starts_with($class, '\\')) {
            return ltrim($class, '\\');
        }

        $namespace = $this->inner($context, $attributes)['namespace'];

        // As Laravel does: a name that starts with the group's namespace already has it.
        if ($namespace === '' || str_starts_with($class, "{$namespace}\\")) {
            return $class;
        }

        return "{$namespace}\\{$class}";
    }

    /**
     * The middleware a call names, each as written or as the class its alias stands for.
     *
     * @param  list<array{0: int, 1: int}>  $arguments
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     * @return list<string>
     */
    private function middleware(array $arguments, array $tokens, string $file): array
    {
        $names = [];

        foreach ($arguments as [$from, $to]) {
            $names = array_merge($names, $this->names($this->value($tokens, $from, $to, file: $file)));
        }

        return $names;
    }

    /**
     * Middleware as a value gives it: a name, a list of them, or something this cannot
     * read, which is kept as "?" so that no one concludes the route is public.
     *
     * @return list<string>
     */
    private function names(mixed $value): array
    {
        if ($value === null) {
            return ['?'];
        }

        return array_map(fn (mixed $name): string => is_string($name) ? $this->named($name) : '?', is_array($value) ? array_values($value) : [$value]);
    }

    /** A middleware by its class where an alias names one, with its parameters. */
    private function named(string $name): string
    {
        $name = ltrim($name, "\0");
        [$alias, $parameters] = array_pad(explode(':', $name, 2), 2, null);

        return isset($this->aliases[$alias]) ? $this->aliases[$alias].($parameters !== null ? ":{$parameters}" : '') : $name;
    }

    /**
     * What an expression is, as far as it can be known without running it: a string,
     * a class (marked with a leading NUL), an array, a file a path call names, or a
     * closure as the tokens of its body. Null for anything else.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private function value(array $tokens, int $from, int $to, string $file): mixed
    {
        $parts = [];
        $at = $from;

        while ($at < $to) {
            $token = $tokens[$at];

            if ($token === '.') {
                $at++;

                continue;
            }

            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $parts[] = reach_routes_string($token[1]);
                $at++;

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_FUNCTION, T_FN, T_STATIC], true)) {
                $open = $this->find($tokens, $at, $to, $token[0] === T_FN ? '=>' : '{');

                if ($open === null) {
                    return null;
                }

                return ['closure' => $token[0] === T_FN ? [$open + 1, $to] : [$open + 1, $this->closing($tokens, $open)]];
            }

            if ($token === '[' || (is_array($token) && $token[0] === T_ARRAY)) {
                $open = $token === '[' ? $at : $at + 1;
                $close = $this->closing($tokens, $open);
                $array = [];

                foreach ($this->arguments($tokens, $open, $close) as [$itemFrom, $itemTo]) {
                    $arrow = $this->find($tokens, $itemFrom, $itemTo, '=>');

                    if ($arrow === null) {
                        $array[] = $this->value($tokens, $itemFrom, $itemTo, file: $file);
                    } else {
                        $key = $this->value($tokens, $itemFrom, $arrow, file: $file);
                        $array[is_string($key) ? $key : count($array)] = $this->value($tokens, $arrow + 1, $itemTo, file: $file);
                    }
                }

                $parts[] = $array;
                $at = $close + 1;

                continue;
            }

            // A class: Name::class.
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
                && is_array($tokens[$at + 1] ?? null) && $tokens[$at + 1][0] === T_DOUBLE_COLON && is_array($tokens[$at + 2] ?? null) && strtolower($tokens[$at + 2][1]) === 'class') {
                [$namespace, $uses] = reach_routes_imports((string) file_get_contents($file));
                $parts[] = "\0".reach_routes_resolve($token[1], $namespace, $uses);
                $at += 3;

                continue;
            }

            // A path: base_path('routes/x.php'), __DIR__, dirname(__DIR__).
            if (is_array($token) && $token[0] === T_DIR) {
                $parts[] = dirname($file);
                $at++;

                continue;
            }

            if (is_array($token) && in_array(strtolower($token[1]), ['base_path', 'dirname'], true) && ($tokens[$at + 1] ?? null) === '(') {
                $close = $this->closing($tokens, $at + 1);
                $inside = $this->value($tokens, $at + 2, $close, file: $file);
                $parts[] = strtolower($token[1]) === 'dirname' ? dirname((string) $inside) : $this->root.'/'.ltrim((string) $inside, '/');
                $at = $close + 1;

                continue;
            }

            if (is_array($token) && $token[0] === T_VARIABLE && array_key_exists($token[1], $this->variables) && ! in_array($tokens[$at + 1] ?? null, ['->', '['], true)) {
                $parts[] = $this->variables[$token[1]];
                $at++;

                continue;
            }

            // $this->namespace in a RouteServiceProvider.
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$this' && is_array($tokens[$at + 2] ?? null) && isset($this->properties[$tokens[$at + 2][1]])) {
                $parts[] = $this->properties[$tokens[$at + 2][1]];
                $at += 3;

                continue;
            }

            return null;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        // A concatenation: strings only, a class among them as its name.
        foreach ($parts as $part) {
            if (! is_string($part)) {
                return null;
            }
        }

        return implode('', array_map(fn (string $part): string => ltrim($part, "\0"), $parts));
    }

    /**
     * Whether the tokens at a position start a chain on the Route facade.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private function isRoute(array $tokens, int $at): bool
    {
        $token = $tokens[$at];

        return is_array($token) && in_array(strtolower(ltrim($token[1], '\\')), ['route', 'illuminate\support\facades\route'], true)
            && is_array($tokens[$at + 1] ?? null) && $tokens[$at + 1][0] === T_DOUBLE_COLON
            && is_array($tokens[$at + 2] ?? null) && ($tokens[$at + 3] ?? null) === '(';
    }

    /**
     * The arguments between a bracket and its closing one, each as a token range.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     * @return list<array{0: int, 1: int}>
     */
    private function arguments(array $tokens, int $open, int $close): array
    {
        $arguments = [];
        $start = $open + 1;
        $depth = 0;

        for ($at = $open + 1; $at < $close; $at++) {
            $token = $tokens[$at];

            if (in_array($token, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                $arguments[] = [$start, $at];
                $start = $at + 1;
            }
        }

        if ($start < $close) {
            $arguments[] = [$start, $close];
        }

        return $arguments;
    }

    /**
     * The token that closes the bracket at a position.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private function closing(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($at = $open; $at < $count; $at++) {
            $token = $tokens[$at];

            if (in_array($token, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true) && --$depth === 0) {
                return $at;
            }
        }

        return $count - 1;
    }

    /**
     * The first token of a kind at depth 0 between two positions.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private function find(array $tokens, int $from, int $to, string $wanted): ?int
    {
        $depth = 0;

        for ($at = $from; $at < $to; $at++) {
            $token = $tokens[$at];
            $text = is_array($token) ? $token[1] : $token;

            if ($depth === 0 && $text === $wanted) {
                return $at;
            }

            if (in_array($token, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']'], true)) {
                $depth--;
            }
        }

        return null;
    }

    /**
     * Where the statement from a position ends: its semicolon at depth 0.
     *
     * @param  list<string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private function statementEnd(array $tokens, int $from, int $to): int
    {
        return $this->find($tokens, $from, $to, ';') ?? $to;
    }

    /** @param  string|array{0: int, 1: string, 2: int}  $token */
    private function is(string|array $token, string $name): bool
    {
        return is_array($token) && strcasecmp($token[1], $name) === 0;
    }

    /**
     * A file's tokens without whitespace and comments.
     *
     * @return list<string|array{0: int, 1: string, 2: int}>
     */
    private function tokens(string $source): array
    {
        return array_values(array_filter(@token_get_all($source) ?: [], fn (string|array $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)));
    }
}

/**
 * A file's namespace and imports, by the lower-case name it uses for each.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function reach_routes_imports(string $source): array
{
    static $known = [];

    if (isset($known[$key = md5($source)])) {
        return $known[$key];
    }

    $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $found) === 1 ? $found[1] : '';
    $uses = [];
    preg_match_all('/^use\s+([\w\\\\]+?)(?:\\\\\{([^}]*)\}|\s+as\s+(\w+))?\s*;/mi', $source, $imports, PREG_SET_ORDER);

    foreach ($imports as $import) {
        $names = ($import[2] ?? '') !== ''
            ? array_map(fn (string $name): array => preg_split('/\s+as\s+/i', trim($name)) + [1 => null], array_filter(explode(',', $import[2]), fn (string $name): bool => trim($name) !== ''))
            : [['', ($import[3] ?? '') !== '' ? $import[3] : null]];

        foreach ($names as [$name, $alias]) {
            $full = ltrim($import[1].($name !== '' ? '\\'.$name : ''), '\\');
            $uses[strtolower($alias ?? substr((string) strrchr('\\'.$full, '\\'), 1))] = $full;
        }
    }

    return $known[$key] = [$namespace, $uses];
}

/**
 * A class name as a file writes it, in full.
 *
 * @param  array<string, string>  $uses
 */
function reach_routes_resolve(string $name, string $namespace, array $uses): string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }

    $parts = explode('\\', $name, 2);

    if (isset($uses[strtolower($parts[0])])) {
        return $uses[strtolower($parts[0])].(isset($parts[1]) ? "\\{$parts[1]}" : '');
    }

    return ltrim("{$namespace}\\{$name}", '\\');
}

/** A string literal's value: a single-quoted one unescapes only \\ and \'. */
function reach_routes_string(string $literal): string
{
    $inner = substr($literal, 1, -1);

    return $literal[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner) : stripcslashes($inner);
}

/**
 * The string literals in a piece of code, in order: a double-quoted one with a
 * variable in it up to that variable, as can:….
 *
 * @return list<string>
 */
function reach_routes_literals(string $code): array
{
    preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|\\\\?([\w\\\\]+)::class/', $code, $found, PREG_SET_ORDER);
    $literals = [];

    foreach ($found as $match) {
        if (($match[3] ?? '') !== '') {
            $literals[] = $match[3];
        } elseif (($match[2] ?? '') !== '') {
            $literals[] = preg_match('/^([^{$]*)[{$]/', $match[2], $start) === 1 ? $start[1].'…' : stripcslashes($match[2]);
        } else {
            $literals[] = str_replace(['\\\\', "\\'"], ['\\', "'"], $match[1]);
        }
    }

    return $literals;
}

/** PHP source without its comments, so that a line commented out says nothing. */
function reach_routes_code(string $source): string
{
    $code = '';

    foreach (@token_get_all($source) ?: [] as $token) {
        $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($token[1], "\n")) : $token[1]) : $token;
    }

    return $code;
}

/** The text from a bracket to the one that closes it, both included. */
function reach_routes_bracket(string $source, int $open): string
{
    $pairs = ['[' => ']', '(' => ')', '{' => '}'];
    $close = $pairs[$source[$open] ?? ''] ?? null;

    if ($close === null) {
        return '';
    }

    $depth = 0;
    $length = strlen($source);

    for ($at = $open; $at < $length; $at++) {
        if ($source[$at] === $source[$open]) {
            $depth++;
        } elseif ($source[$at] === $close && --$depth === 0) {
            return substr($source, $open, $at - $open + 1);
        }
    }

    return substr($source, $open);
}
