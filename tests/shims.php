<?php

/**
 * Minimal stand-ins for the parts of Paymenter and Laravel the extension
 * touches, so it can be exercised without a Paymenter install.
 *
 * Each one mirrors the real signature it replaces (checked against the
 * Paymenter source), but these are NOT the real classes: a passing run proves
 * the extension's own logic and its HTTP conversation with Wizarr, not that
 * Paymenter loads it.
 */

namespace App\Attributes {
    use Attribute;

    #[Attribute(Attribute::TARGET_CLASS)]
    class ExtensionMeta
    {
        public function __construct(
            public string $name,
            public string $description,
            public string $version,
            public string $author,
            public string $url = '',
            public string $icon = '',
            public string $extensionId = 'unknown',
        ) {}
    }
}

namespace App\Exceptions {
    /** Paymenter shows the message of this one to the customer as a notice. */
    class DisplayException extends \Exception {}
}

namespace App\Classes\Extension {
    class Extension
    {
        public function __construct(public $config = []) {}

        public function config($key)
        {
            return $this->config[$key] ?? null;
        }
    }

    class Server extends Extension {}
}

namespace App\Models {
    /** Stands in for the Eloquent relation returned by $service->properties(). */
    class PropertyBag
    {
        /** @var array<string, array{name: string, value: string}> */
        public array $rows = [];

        public function where(string $column, string $value): PropertyFilter
        {
            return new PropertyFilter($this, $value);
        }

        public function updateOrCreate(array $match, array $values): void
        {
            $this->rows[$match['key']] = ['name' => $values['name'], 'value' => $values['value']];
        }
    }

    class PropertyFilter
    {
        public function __construct(private PropertyBag $bag, private string $key) {}

        public function value(string $column): ?string
        {
            return $this->bag->rows[$this->key]['value'] ?? null;
        }

        public function delete(): void
        {
            unset($this->bag->rows[$this->key]);
        }
    }

    class User
    {
        /** @var callable[] User::deleting() listeners, as Eloquent keeps them */
        public static array $deleting = [];

        public static function deleting($callback): void
        {
            self::$deleting[] = $callback;
        }

        public ?int $role_id = null;

        public int $id = 1;

        /** @var Service[] what $user->services()->...->get() returns */
        public array $serviceList = [];

        public function services(): object
        {
            $list = $this->serviceList;

            return new class($list)
            {
                public function __construct(private array $list) {}

                public function with($relations): static
                {
                    return $this;
                }

                public function get(): array
                {
                    return $this->list;
                }
            };
        }

        private PropertyBag $bag;

        public function __construct(public string $email, public string $name = 'Test Customer')
        {
            $this->bag = new PropertyBag;
        }

        public function properties(): PropertyBag
        {
            return $this->bag;
        }
    }

    class Service
    {
        public const STATUS_ACTIVE = 'active';

        public string $status = 'active';

        public ?\DateTimeImmutable $updated_at = null;

        /** ->product->server->extension and ->product->settings, as Paymenter's relations give them */
        public ?object $product = null;

        /** Configurable options: env variable => chosen value's env variable */
        public array $configs = [];

        /** Eloquent's loaded "properties" relation; null when not loaded. */
        public ?array $loadedProperties = null;

        public function unsetRelation($relation): static
        {
            if ($relation === 'properties') {
                $this->loadedProperties = null;
            }

            return $this;
        }

        private PropertyBag $bag;

        public function __construct(public int $id, public User $user)
        {
            $this->bag = new PropertyBag;
        }

        public function properties(): PropertyBag
        {
            return $this->bag;
        }

        /** @var array<int, Service> what Service::whereHas(...)->get() returns */
        public static array $all = [];

        /** Service::whereIn(...)->whereHas(...)->with(...)->get(), as the hourly jobs use it. */
        public static function whereIn(string $column, array $values): object
        {
            return new class
            {
                public function whereHas($relation, $callback = null): static
                {
                    return $this;
                }

                public function with($relations): static
                {
                    return $this;
                }

                public function get(): array
                {
                    return Service::$all;
                }
            };
        }

        public static function whereHas(string $relation, ?\Closure $callback = null): object
        {
            return new class
            {
                public function get(): array
                {
                    return Service::$all;
                }
            };
        }

        /** What ExtensionHelper::getServiceProperties() would hand to a hook. */
        public function propertyArray(): array
        {
            return array_map(fn ($row) => $row['value'], $this->bag->rows);
        }
    }
}

namespace App\Helpers {
    use App\Models\Service;
    use App\Models\User;

    class ExtensionHelper
    {
        /** The tests hand settings over as plain arrays already. */
        public static function settingsToArray($settings): array
        {
            return is_array($settings) ? $settings : [];
        }

        /**
         * Properties plus configurable options, as Paymenter merges them - from
         * $service->properties, which Eloquent loads once and keeps until
         * unsetRelation('properties'). Modelled so a stale read shows up here.
         */
        public static function getServiceProperties($service): array
        {
            $service->loadedProperties ??= $service->propertyArray();

            return $service->loadedProperties + ($service->configs ?? []);
        }
    }

    class NotificationHelper
    {
        /** @var array<int, array{email: string, service: int, data: array}> */
        public static array $sent = [];

        public static bool $fail = false;

        public static function serverCreatedNotification(User $user, Service $service, array $data = []): void
        {
            if (self::$fail) {
                throw new \RuntimeException('SMTP is down');
            }
            self::$sent[] = ['email' => $user->email, 'service' => $service->id, 'data' => $data];
        }
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $warnings = [];

        public static array $infos = [];

        public static function warning(string $message): void
        {
            self::$warnings[] = $message;
        }

        public static function info(string $message): void
        {
            self::$infos[] = $message;
        }
    }

    class Http
    {
        public static function withHeaders(array $headers): PendingRequest
        {
            return new PendingRequest($headers);
        }
    }

    class PendingRequest
    {
        private int $timeout = 30;

        public function __construct(private array $headers) {}

        public function timeout(int $seconds): static
        {
            $this->timeout = $seconds;

            return $this;
        }

        public function get(string $url): Response
        {
            return $this->send('GET', $url);
        }

        public function delete(string $url): Response
        {
            return $this->send('DELETE', $url);
        }

        public function post(string $url, array $data = []): Response
        {
            return $this->send('POST', $url, $data);
        }

        public function put(string $url, array $data = []): Response
        {
            return $this->send('PUT', $url, $data);
        }

        private function send(string $method, string $url, ?array $data = null): Response
        {
            $headers = [];
            foreach ($this->headers as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }

            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_PROXY => '',
            ]);
            if ($data !== null) {
                // Laravel's client sends arrays as a JSON body by default.
                $headers[] = 'Content-Type: application/json';
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            $body = curl_exec($curl);
            if ($body === false) {
                $error = curl_error($curl);
                curl_close($curl);

                // Laravel throws Illuminate\Http\Client\ConnectionException here.
                throw new \RuntimeException('cURL error: ' . $error);
            }
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            return new Response($status, $body);
        }
    }

    class Response
    {
        public function __construct(private int $status, private string $body) {}

        public function status(): int
        {
            return $this->status;
        }

        public function body(): string
        {
            return $this->body;
        }

        public function json()
        {
            return json_decode($this->body, true);
        }
    }
}


/*
 * Framework pieces used by Wizarr::boot(). Method names and behaviour copied
 * from Laravel 13.40 (the version Paymenter v1.5.9 locks), so calling a
 * method that does not exist there fails here too.
 */

namespace App\Events\Service {
    class Created
    {
        public function __construct(public \App\Models\Service $service) {}
    }
}

namespace Illuminate\Console\Scheduling {
    class CallbackEvent
    {
        public ?string $description = null;

        public string $expression = '* * * * *';

        public bool $withoutOverlapping = false;

        public function __construct(public \Closure $callback) {}

        public function hourly(): static
        {
            $this->expression = '0 * * * *';

            return $this;
        }

        public function name($description): static
        {
            $this->description = $description;

            return $this;
        }

        public function withoutOverlapping($expiresAt = 1440, $releaseOnTerminationSignals = true): static
        {
            if (!isset($this->description)) {
                throw new \LogicException("A scheduled event name is required to prevent overlapping. Use the 'name' method before 'withoutOverlapping'.");
            }
            $this->withoutOverlapping = true;

            return $this;
        }
    }

    class Schedule
    {
        /** @var CallbackEvent[] */
        public array $events = [];

        public function call($callback, array $parameters = []): CallbackEvent
        {
            return $this->events[] = new CallbackEvent($callback);
        }
    }
}

namespace Illuminate\Foundation {
    /** Only the Container methods Wizarr::boot() is allowed to rely on. */
    class FakeApplication
    {
        public static ?FakeApplication $instance = null;

        private array $instances = [];

        private array $afterResolving = [];

        public array $bindings = [];

        public string $locale = 'en';

        // As Illuminate\Foundation\Application (Laravel 13.40).
        public function getLocale(): string
        {
            return $this->locale;
        }

        public function setLocale($locale): void
        {
            $this->locale = $locale;
        }

        public function bind($abstract, $concrete = null, $shared = false): void
        {
            $this->bindings[$abstract] = $concrete;
        }

        public function bound($abstract): bool
        {
            return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
        }

        public function instance($abstract, $instance)
        {
            return $this->instances[$abstract] = $instance;
        }

        public function resolved($abstract): bool
        {
            return isset($this->instances[$abstract]);
        }

        public function afterResolving($abstract, ?\Closure $callback = null): void
        {
            $this->afterResolving[$abstract][] = $callback;
        }

        public function make($abstract, array $parameters = [])
        {
            if (isset($this->bindings[$abstract])) {
                return ($this->bindings[$abstract])($this);
            }
            if (!isset($this->instances[$abstract])) {
                $this->instances[$abstract] = new $abstract;
                foreach ($this->afterResolving[$abstract] ?? [] as $callback) {
                    $callback($this->instances[$abstract], $this);
                }
            }

            return $this->instances[$abstract];
        }

        public function __call($method, $parameters)
        {
            // Laravel's Application is Macroable: unknown methods throw this.
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }
    }
}

namespace Illuminate\Support\Facades {
    class Event
    {
        public static array $listeners = [];

        public static function listen($events, $listener = null): void
        {
            self::$listeners[] = [$events, $listener];
        }
    }

    class View
    {
        public static array $namespaces = [];

        public static function addNamespace($namespace, $hints): void
        {
            self::$namespaces[$namespace] = $hints;
        }
    }

    /** Router + RouteRegistrar, as far as routes.php uses them. */
    class Route
    {
        public static array $routes = [];

        public static function middleware($middleware): RouteRegistrar
        {
            return (new RouteRegistrar)->middleware($middleware);
        }

        public static function get($uri, $action): RegisteredRoute
        {
            return RouteRegistrar::$current->add('GET', $uri, $action);
        }

        public static function post($uri, $action): RegisteredRoute
        {
            return RouteRegistrar::$current->add('POST', $uri, $action);
        }
    }

    class RouteRegistrar
    {
        public static ?RouteRegistrar $current = null;

        public array $middleware = [];

        public string $prefix = '';

        public string $name = '';

        public function middleware($middleware): static
        {
            $this->middleware = (array) $middleware;

            return $this;
        }

        public function prefix($prefix): static
        {
            $this->prefix = $prefix;

            return $this;
        }

        public function name($name): static
        {
            $this->name = $name;

            return $this;
        }

        public function group($callback): static
        {
            self::$current = $this;
            $callback();
            self::$current = null;

            return $this;
        }

        /** RouteRegistrar passes get/post/... through to registerRoute(). */
        public function get($uri, $action): RegisteredRoute
        {
            return $this->add('GET', $uri, $action);
        }

        public function add(string $method, string $uri, $action): RegisteredRoute
        {
            // Laravel stores route URIs without leading or trailing slashes.
            $route = new RegisteredRoute($method, trim($this->prefix . '/' . ltrim($uri, '/'), '/'), $action, $this->middleware, $this->name);
            Route::$routes[] = $route;

            return $route;
        }
    }

    class RegisteredRoute
    {
        public function __construct(public string $method, public string $uri, public $action, public array $middleware, public string $name) {}

        public function name($name): static
        {
            $this->name .= $name;

            return $this;
        }
    }
}

namespace {
    function app($abstract = null)
    {
        // Laravel always has an application; create one the first time.
        $app = \Illuminate\Foundation\FakeApplication::$instance ??= new \Illuminate\Foundation\FakeApplication;

        return $abstract === null ? $app : $app->make($abstract);
    }
}


namespace Illuminate\Routing {
    /** Router::pushMiddlewareToGroup(), as in Laravel 13.40. */
    class Router
    {
        public array $middlewareGroups = [];

        public function pushMiddlewareToGroup($group, $middleware): static
        {
            $this->middlewareGroups[$group] ??= [];
            if (!in_array($middleware, $this->middlewareGroups[$group])) {
                $this->middlewareGroups[$group][] = $middleware;
            }

            return $this;
        }
    }
}


namespace Illuminate\Http {
    /** Only so ShopGate.php can be loaded; the middleware glue itself is not run here. */
    class Request {}
}
