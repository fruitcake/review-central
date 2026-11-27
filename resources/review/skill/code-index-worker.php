<?php

/**
 * A Mago analyzer extension that writes the reviewed project's call graph, for
 * reach.php to follow instead of method names. Mago resolves each call by the
 * type of what it is called on, so `$lesson->pay()` reaches Lesson::pay and not
 * every pay() there is. Run by reviewer-env.sh, never on its own:
 *
 *   REACH_INDEX=<directory> MAGO_SDK=<vendor/autoload.php of carthage-software/mago> mago analyze
 *
 * It writes three files to REACH_INDEX, every name in lower case:
 *   edges.tsv      caller, callee: `class::method` each, or `file:<path>` for code outside any class
 *   classes.tsv    class, the file it is declared in
 *   ancestors.tsv  class, a parent class or interface of it
 *
 * Eloquent is typed the way Larastan reads it: Model::find() gives the model,
 * Model::where() and a local scope a builder of it, $lesson->participants() a
 * relation to the related model and $lesson->participants a collection of it.
 * Without that, every call on a model reached through one of them would be
 * missing. code-index-models.php reads the scopes and relations from app/.
 */

declare(strict_types=1);

use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\InvocationKind;
use Mago\Sdk\Analyzer\Metadata\MemberIdentifier;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\ReferenceKind;
use Mago\Sdk\Analyzer\ReferenceOrigin;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Extension;
use Mago\Sdk\Worker;

require (string) getenv('MAGO_SDK');
require __DIR__.'/code-index-models.php';

const ELOQUENT_MODEL = 'illuminate\database\eloquent\model';

/** Whether a class is an Eloquent model, also where vendor/ is missing and the base class is only a name. */
function code_index_is_model(Codebase $codebase, string $class): bool
{
    $names = array_merge([$class], $codebase->getClassAncestors($class));

    foreach ($names as $name) {
        if (strtolower($name) === ELOQUENT_MODEL) {
            return true;
        }

        $metadata = $codebase->getClass($name);

        foreach (array_merge($metadata?->parentClasses ?? [], $metadata?->unresolvedHierarchyDependencies ?? []) as $parent) {
            if (strtolower(ltrim((string) $parent, '\\')) === ELOQUENT_MODEL) {
                return true;
            }
        }
    }

    return false;
}

final class CodeIndex implements AfterAnalysisHook, Plugin
{
    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('code-index', 'code-index', 'Writes the project\'s call graph for the review.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook($this);
        $root = rtrim((string) getenv('REACH_ROOT'), '/');
        $models = code_index_models("{$root}/app");
        $registry->registerMethodReturnTypeProvider(new SignedInUser(code_index_auth_models($root)));
        $registry->registerFunctionReturnTypeProvider(new DefaultGuard);
        $registry->registerMethodReturnTypeProvider(new NamedGuard);
        $registry->registerMethodReturnTypeProvider(new EloquentStatics($models['scopes'], $models['marked']));

        if ($models['relations'] !== []) {
            $relations = new EloquentRelations($models['relations']);
            $registry->registerMethodReturnTypeProvider($relations);
            $registry->registerPropertyTypeProvider($relations);
        }
    }

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $directory = rtrim((string) getenv('REACH_INDEX'), '/');
        $root = rtrim((string) getenv('REACH_ROOT'), '/');
        $own = [];

        foreach ($context->analysis->files as $file) {
            $own[$file->file] = true;
        }

        // The project's own class-likes, read from its files: the codebase's list also
        // holds vendor's, and can be longer than one answer may be.
        $names = [];

        foreach (array_keys($own) as $file) {
            $names = array_merge($names, self::declared(str_starts_with($file, '/') ? $file : "{$root}/{$file}"));
        }

        $codebase = $context->codebase;
        $origins = [];
        $classes = fopen("{$directory}/classes.tsv", 'w');
        $ancestors = fopen("{$directory}/ancestors.tsv", 'w');

        foreach (array_chunk(array_values(array_unique($names)), 200) as $chunk) {
            // Class-likes, not classes only: a trait's methods are origins of their own.
            foreach ($codebase->getMultipleClassLikes($chunk) as $class) {
                if ($class === null || $class->name === '' || str_contains($class->name, '@')) {
                    continue;
                }

                $name = strtolower($class->name);
                fwrite($classes, $name."\t".self::relative((string) $class->location->file, $root)."\n");

                foreach (array_unique(array_merge($class->parentClasses, $class->parentInterfaces)) as $ancestor) {
                    fwrite($ancestors, $name."\t".strtolower(ltrim((string) $ancestor, '\\'))."\n");
                }

                foreach ($class->methods as $method) {
                    if ($method !== '') {
                        $origins[] = ReferenceOrigin::symbol(new MemberIdentifier($class->name, $method));
                    }
                }
            }
        }

        fclose($classes);
        fclose($ancestors);

        foreach (array_keys($own) as $file) {
            $origins[] = ReferenceOrigin::file($file);
        }

        $edges = fopen("{$directory}/edges.tsv", 'w');

        // The host answers at most 65536 items at once.
        foreach (array_chunk($origins, 50) as $chunk) {
            foreach ($this->referencesFrom($context, $chunk) as $references) {
                foreach ($references as $reference) {
                    if ($reference->kind !== ReferenceKind::Body || ! $reference->target instanceof MemberIdentifier) {
                        continue;
                    }

                    $source = $reference->source->symbol;
                    $from = $source instanceof MemberIdentifier ? $source->class.'::'.$source->member : 'file:'.self::relative((string) $reference->source->file, $root);
                    fwrite($edges, strtolower($from)."\t".strtolower($reference->target->class.'::'.$reference->target->member)."\n");
                }
            }
        }

        fclose($edges);
    }

    /**
     * The references from these origins; one the host refuses is left out rather than failing the rest.
     *
     * @param  list<ReferenceOrigin>  $origins
     * @return array<int, array<int, mixed>>
     */
    private function referencesFrom(AfterAnalysisContext $context, array $origins): array
    {
        try {
            return $context->analysis->references->getMultipleReferencesFrom($origins);
        } catch (Throwable) {
            if (count($origins) === 1) {
                return [];
            }

            return array_merge(...array_map(fn (ReferenceOrigin $origin): array => $this->referencesFrom($context, [$origin]), $origins));
        }
    }

    /**
     * The classes, interfaces, traits and enums a file declares, by full name.
     *
     * @return list<string>
     */
    private static function declared(string $path): array
    {
        $tokens = @token_get_all((string) @file_get_contents($path)) ?: [];
        $namespace = '';
        $names = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            }

            if (! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            // Not Foo::class, and not an anonymous class.
            $previous = $i - 1;

            while (isset($tokens[$previous]) && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }

            if (isset($tokens[$previous]) && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; isset($tokens[$j]); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $names[] = ltrim($namespace.'\\'.$tokens[$j][1], '\\');

                    break;
                }

                if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                    break;
                }
            }
        }

        return $names;
    }

    private static function relative(string $file, string $root): string
    {
        return $root !== '' && str_starts_with($file, "{$root}/") ? substr($file, strlen($root) + 1) : $file;
    }
}

/**
 * Eloquent's forwarding, as Larastan reads it: Model::find() is a model, and
 * Model::where() and Model::active() a builder of it, as is active() on one.
 */
final class EloquentStatics implements MethodReturnTypeProvider
{
    private const MODEL = ['find', 'findorfail', 'findornew', 'first', 'firstorfail', 'firstornew', 'firstorcreate', 'firstwhere', 'sole', 'create', 'forcecreate', 'make', 'updateorcreate'];

    private const BUILDER = ['query', 'where', 'wherein', 'wherenotin', 'wherenull', 'wherenotnull', 'wherehas', 'wherebelongsto', 'with', 'withcount', 'withtrashed', 'onlytrashed', 'orderby', 'orderbydesc', 'latest', 'oldest', 'select', 'wherekey', 'has', 'doesnthave', 'withoutglobalscope', 'withoutglobalscopes', 'lockforupdate', 'newquery'];

    /**
     * @param  list<string>  $scopes  the project's local scopes, by the name they are called with
     * @param  array<string, list<string>>  $marked  the ones marked with #[Scope], by the class that declares them
     */
    public function __construct(private readonly array $scopes, private readonly array $marked = []) {}

    public function getTargets(): array
    {
        // By name, on any class: a model's own base class may route them through a docblock mixin.
        return array_map(MethodTarget::anyClass(...), array_values(array_unique(array_merge(self::MODEL, self::BUILDER, $this->scopes))));
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $invocation = $context->invocation;
        $name = strtolower($invocation->name);

        if ($invocation->kind !== InvocationKind::StaticMethod) {
            return in_array($name, $this->scopes, true) ? $this->sameBuilder($context->codebase, $invocation->receiverType, $name) : null;
        }

        $class = null;

        foreach ($invocation->receiverType?->atomicTypes ?? [] as $atomic) {
            if ($atomic instanceof NamedObjectType) {
                $class = $atomic->name;
            } elseif ($atomic instanceof ClassLikeStringType && $atomic->literal !== null) {
                $class = $atomic->literal;
            }
        }

        if ($class === null || strtolower($class) === ELOQUENT_MODEL || ! code_index_is_model($context->codebase, $class)) {
            return null;
        }

        // find([1, 2]) and the like give a collection of the model, find(1) the model.
        if (in_array($name, ['find', 'findorfail', 'findornew'], true) && self::isMany($invocation->arguments[0]->type ?? null)) {
            return Type::namedObject('Illuminate\Database\Eloquent\Collection', Type::int(), Type::namedObject($class));
        }

        return match (true) {
            in_array($name, self::MODEL, true) => Type::namedObject($class),
            in_array($name, self::BUILDER, true), $this->isScopeOf($context->codebase, $class, $name) => Type::namedObject('Illuminate\Database\Eloquent\Builder', Type::namedObject($class)),
            default => null,
        };
    }

    /** A builder of a model again, for a scope of that model called on one; nothing for a call on anything else. */
    private function sameBuilder(Codebase $codebase, ?Type $receiver, string $name): ?Type
    {
        foreach ($receiver?->atomicTypes ?? [] as $atomic) {
            if (! $atomic instanceof NamedObjectType || strtolower($atomic->name) !== 'illuminate\\database\\eloquent\\builder') {
                continue;
            }

            foreach ($atomic->parameters ?? [] as $parameter) {
                foreach ($parameter->atomicTypes as $model) {
                    if ($model instanceof NamedObjectType && $this->isScopeOf($codebase, $model->name, $name)) {
                        return Type::namedObject('Illuminate\Database\Eloquent\Builder', ...$atomic->parameters);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Whether a model has a local scope of this name, declared by itself, a parent or
     * a trait: a method of the name itself is the scope only when #[Scope] marks it.
     */
    private function isScopeOf(Codebase $codebase, string $class, string $name): bool
    {
        if (! in_array($name, $this->scopes, true)) {
            return false;
        }

        $own = $codebase->getDeclaringMethod($class, $name);

        // A @method in a docblock, as an IDE helper writes for a scope, is no method of its own.
        if ($own !== null && ($own->flags->bits & MetadataFlags::MAGIC_METHOD) === 0) {
            return in_array($name, $this->marked[strtolower((string) $own->identifier->class)] ?? [], true);
        }

        return $codebase->getDeclaringMethod($class, "scope{$name}") !== null;
    }

    /** Whether an id argument is several ids: an array, or a collection or other Arrayable. */
    private static function isMany(?Type $type): bool
    {
        foreach ($type?->atomicTypes ?? [] as $atomic) {
            if (! $atomic instanceof NamedObjectType) {
                if (preg_match('/^(array|list|non-empty-(array|list))\b/i', (string) $atomic) === 1) {
                    return true;
                }

                continue;
            }

            if (preg_match('/(Collection|Arrayable)$/i', $atomic->name) === 1) {
                return true;
            }
        }

        return false;
    }
}

/**
 * A model's relations, as Larastan reads them from the method's body:
 * $lesson->participants() is a relation to the related model, whatever the
 * declared return type leaves out, and $lesson->participants is the related
 * model, or a collection of it for a relation to many.
 */
final class EloquentRelations implements MethodReturnTypeProvider, PropertyTypeProvider
{
    /**
     * @param  array<string, array<string, array{method: string, relation: string, related: string, many: bool}>>  $relations  by the class or trait that declares them
     */
    public function __construct(private readonly array $relations) {}

    public function getTargets(): array
    {
        // As each is written: Mago matches a property's name by its case.
        $names = array_values(array_unique(array_column(array_merge(...array_values($this->relations)), 'method')));

        return array_merge(array_map(MethodTarget::anyClass(...), $names), array_map(PropertyTarget::anyClass(...), $names));
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $invocation = $context->invocation;

        if ($invocation->kind === InvocationKind::StaticMethod) {
            return null;
        }

        [$model, $relation] = $this->relation($context->codebase, $invocation->receiverType, $invocation->name) ?? [null, null];

        if ($relation === null) {
            return null;
        }

        // Through relations name the intermediate model between the two.
        $parameters = str_ends_with($relation['relation'], 'Through')
            ? [Type::namedObject($relation['related']), Type::namedObject(ELOQUENT_MODEL), Type::namedObject($model)]
            : [Type::namedObject($relation['related']), Type::namedObject($model)];

        return Type::namedObject($relation['relation'], ...$parameters);
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        [$model, $relation] = $this->relation($context->codebase, $context->access->receiverType, $context->access->property) ?? [null, null];

        // A model's own @property says it already, and is what the read is recorded against.
        if ($relation === null || $context->codebase->magicPropertyExists($model, '$'.$context->access->property)) {
            return null;
        }

        $related = Type::namedObject($relation['related']);

        return new PropertyType($relation['many'] ? Type::namedObject('Illuminate\Database\Eloquent\Collection', Type::int(), $related) : $related);
    }

    /**
     * The model a call or property is on, and the relation of that name it has,
     * declared by itself, a parent or a trait, with its related model as this
     * call has it: static is the model it is called on, and so is self in a
     * trait. A related model the codebase does not know is left to Mago.
     *
     * @return array{0: string, 1: array{method: string, relation: string, related: string, many: bool}}|null
     */
    private function relation(Codebase $codebase, ?Type $receiver, string $name): ?array
    {
        $name = strtolower(ltrim($name, '$'));

        foreach ($receiver?->atomicTypes ?? [] as $atomic) {
            if (! $atomic instanceof NamedObjectType) {
                continue;
            }

            $declaringClass = (string) $codebase->getDeclaringMethod($atomic->name, $name)?->identifier->class;
            $relation = $this->relations[strtolower($declaringClass)][$name] ?? null;

            if ($relation === null) {
                continue;
            }

            $relation['related'] = match ($relation['related']) {
                'static' => $atomic->name,
                'self' => $codebase->traitExists($declaringClass) ? $atomic->name : $declaringClass,
                default => $relation['related'],
            };

            return $codebase->classExists($relation['related']) ? [$atomic->name, $relation] : null;
        }

        return null;
    }
}

/**
 * The user someone is signed in as, as Larastan reads it from config/auth.php:
 * auth()->user(), Auth::user() and $request->user() are the default guard's
 * model. With a guard named, as auth('member')->user() or $request->user('api'),
 * which guard it is the index cannot follow, so it is any guard's model: a path
 * too many rather than one missing.
 */
final class SignedInUser implements MethodReturnTypeProvider
{
    /** The auth manager, which forwards user() to the default guard, and what DefaultGuard makes auth() without a guard. */
    private const MANAGERS = ['illuminate\contracts\auth\factory', 'illuminate\auth\authmanager', DefaultGuard::CLASS_NAME];

    private const GUARD = 'illuminate\contracts\auth\guard';

    private const REQUEST = 'illuminate\http\request';

    /** The Auth facade, imported or by its global alias. */
    private const FACADES = ['illuminate\support\facades\auth', 'auth'];

    /**
     * @param  array{default: list<string>, all: list<string>}  $models
     */
    public function __construct(private readonly array $models) {}

    public function getTargets(): array
    {
        return [MethodTarget::anyClass('user')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $invocation = $context->invocation;
        $named = $invocation->arguments !== [];

        foreach ($invocation->receiverType?->atomicTypes ?? [] as $atomic) {
            $class = match (true) {
                $atomic instanceof NamedObjectType => $atomic->name,
                $atomic instanceof ClassLikeStringType && $atomic->literal !== null => $atomic->literal,
                default => null,
            };

            if ($class === null) {
                continue;
            }

            $name = strtolower(ltrim($class, '\\'));
            $ancestors = array_map(fn (string $ancestor): string => strtolower(ltrim($ancestor, '\\')), $context->codebase->getClassAncestors($class));

            $models = match (true) {
                $invocation->kind === InvocationKind::StaticMethod => in_array($name, self::FACADES, true) ? $this->models['default'] : null,
                in_array($name, self::MANAGERS, true) => $this->models['default'],
                $name === self::REQUEST || in_array(self::REQUEST, $ancestors, true) => $named ? $this->models['all'] : $this->models['default'],
                $name === self::GUARD || in_array(self::GUARD, $ancestors, true) => $this->models['all'],
                default => null,
            };

            if ($models !== null) {
                return $this->user($context->codebase, $models);
            }
        }

        return null;
    }

    /**
     * One of these models, or no one.
     *
     * @param  list<string>  $models
     */
    private function user(Codebase $codebase, array $models): ?Type
    {
        $types = array_map(fn (string $model): Type => Type::namedObject($model), array_values(array_filter($models, $codebase->classExists(...))));

        return $types === [] ? null : Type::union(Type::null(), ...$types);
    }
}

/**
 * auth() without a guard: the manager, whose user() is the default guard's. The
 * manager declares no user(), so a call to it would reach no provider; given as
 * the manager or the session guard, which does, it reaches SignedInUser as the
 * default guard.
 * With a guard named, auth('member') keeps the guard Laravel's stub gives it.
 */
final class DefaultGuard implements FunctionReturnTypeProvider
{
    public const CLASS_NAME = 'illuminate\auth\sessionguard';

    public function getTargets(): array
    {
        return [FunctionTarget::exact('auth')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        // The manager too: auth()->guard('member') is still the manager's guard().
        return $context->invocation->arguments === []
            ? Type::union(Type::namedObject('Illuminate\Contracts\Auth\Factory'), Type::namedObject('Illuminate\Auth\SessionGuard'))
            : null;
    }
}

/**
 * auth()->guard('member') on what DefaultGuard gives auth(): the session guard
 * has no guard(), so without this the call would not be typed at all. It is the
 * guard Laravel's contract returns.
 */
final class NamedGuard implements MethodReturnTypeProvider
{
    public function getTargets(): array
    {
        return [MethodTarget::anyClass('guard')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        foreach ($context->invocation->receiverType?->atomicTypes ?? [] as $atomic) {
            if ($atomic instanceof NamedObjectType && strtolower($atomic->name) === DefaultGuard::CLASS_NAME) {
                return Type::namedObject('Illuminate\Contracts\Auth\StatefulGuard');
            }
        }

        return null;
    }
}

(new Worker(new Extension(identifier: 'review-central/code-index', name: 'Code index', version: '1.0.0', analyzerPlugins: [new CodeIndex])))->run();
