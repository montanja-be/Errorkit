<?php

namespace LaraBug;

use Throwable;
use LaraBug\Http\Client;
use LaraBug\Filters\DataFilter;
use LaraBug\Support\Throttle;
use LaraBug\Support\Fingerprint;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;

class LaraBug
{
    /** @var Client */
    private $client;

    /** @var DataFilter */
    private $dataFilter;

    /** @var Throttle */
    private $throttle;

    /** @var null|string */
    private $lastExceptionId;

    /** @var array */
    private static $customContext = [];

    /**
     * Reports captured during a web request, sent once the response is out.
     *
     * @var array
     */
    private static $deferred = [];

    /** @var bool */
    private static $flushRegistered = false;

    /**
     * Re-entry guard. Set while handle() is executing so any exception
     * thrown *inside* the capture path (HTTP errors, serialization, etc.)
     * can't recursively trigger another capture and blow the stack.
     *
     * @var bool
     */
    private static $capturing = false;

    /**
     * @param Client $client
     * @param Throttle|null $throttle
     */
    public function __construct(Client $client, ?Throttle $throttle = null)
    {
        $this->client = $client;
        $this->dataFilter = new DataFilter(config('larabug.blacklist', []));
        $this->throttle = $throttle ?: new Throttle();
    }

    /**
     * Set custom context data that will be sent with the next exception
     *
     * @param array $context
     * @return void
     */
    public static function context(array $context)
    {
        self::$customContext = array_merge(self::$customContext, $context);
    }

    /**
     * Clear custom context data
     *
     * @return void
     */
    public static function clearContext()
    {
        self::$customContext = [];
    }

    /**
     * @param Throwable $exception
     * @param string $fileType
     * @return bool|mixed
     */
    public function handle(Throwable $exception, $fileType = 'php', array $customData = [])
    {
        // Drop any capture that re-enters while another capture is still in flight.
        // Without this, an error thrown inside logError() would be caught by
        // Laravel's handler and fed back into handle(), looping forever.
        if (self::$capturing) {
            return false;
        }

        self::$capturing = true;

        try {
            if ($this->isSkipEnvironment()) {
                return false;
            }

            $data = $this->getExceptionData($exception);

            if ($this->isSkipException($data['class'])) {
                return false;
            }

            if ($fileType == 'javascript') {
                $data['fullUrl'] = $customData['url'];
                $data['file'] = $customData['file'];
                $data['file_type'] = $fileType;
                $data['error'] = $customData['message'];
                $data['exception'] = $customData['stack'];
                $data['line'] = $customData['line'];
                $data['class'] = null;

                // A JavaScript error's file is a URL in the browser, never a path on
                // this server, so there is no source to read here. Reading it handed
                // any anonymous caller an arbitrary file read, and the browser stack
                // in $data['exception'] is the context that belongs in the report.
                $data['executor'] = [];
            }

            // The same error within the window is counted instead of sent, and
            // that count rides along with the next report of it.
            $occurrences = $this->throttle->occurrence($data['fingerprint'], (int) config('larabug.sleep', 60));

            if ($occurrences === 0) {
                return false;
            }

            // One site must never flood the server: past the limit, or while the
            // server asked us to back off, reports are dropped and only counted.
            if (! $this->throttle->allow((int) config('larabug.rate_limit.per_minute', 10))) {
                // Forgotten, so the error is sent again as soon as there is room.
                $this->throttle->forget($data['fingerprint']);
                $this->throttle->addDropped($occurrences);

                return false;
            }

            $data['occurrences'] = $occurrences;
            $data['dropped'] = $this->throttle->pullDropped();

            $payload = [
                'exception' => $data,
                'user' => $this->getUser(),
            ];

            if ($this->shouldDefer()) {
                $this->defer($payload);

                return true;
            }

            return $this->send($payload);
        } catch (Throwable $inner) {
            // Anything that blows up inside capture must NOT re-enter Laravel's
            // exception handler — that would loop back into this method. Swallow
            // to stderr and let the outer process keep going.
            error_log('[Errorkit] capture failed: '.$inner->getMessage());

            return false;
        } finally {
            self::$capturing = false;
        }
    }

    /**
     * Send every report that waited for the response to go out.
     *
     * @return void
     */
    public function flush()
    {
        $payloads = self::$deferred;
        self::$deferred = [];

        foreach ($payloads as $payload) {
            try {
                $this->send($payload);
            } catch (Throwable $e) {
                error_log('[Errorkit] flush failed: '.$e->getMessage());
            }
        }
    }

    /**
     * @param array $payload
     * @return bool|mixed
     */
    private function send(array $payload)
    {
        // Another report in this process already found the server unreachable.
        if ($this->throttle->isBackingOff()) {
            $this->throttle->addDropped(1 + ($payload['exception']['dropped'] ?? 0));

            return false;
        }

        $rawResponse = $this->logError($payload);

        if (! $rawResponse) {
            // No answer at all: back off, so a server that is down does not
            // cost every following request on this site a full timeout.
            $this->throttle->backOff((int) config('larabug.backoff', 60));
            $this->throttle->addDropped(1 + ($payload['exception']['dropped'] ?? 0));

            return false;
        }

        $status = $rawResponse->getStatusCode();

        if ($status === 429 || $status >= 500) {
            $retryAfter = (int) $rawResponse->getHeaderLine('Retry-After');

            $this->throttle->backOff($retryAfter > 0 ? min($retryAfter, 3600) : (int) config('larabug.backoff', 60));
            $this->throttle->addDropped(1 + ($payload['exception']['dropped'] ?? 0));

            return false;
        }

        $response = json_decode((string) $rawResponse->getBody());

        if (isset($response->id)) {
            $this->setLastExceptionId((string) $response->id);
        }

        return $response;
    }

    /**
     * @return bool
     */
    private function shouldDefer()
    {
        return config('larabug.deferred', true) && ! App::runningInConsole();
    }

    /**
     * @param array $payload
     * @return void
     */
    private function defer(array $payload)
    {
        self::$deferred[] = $payload;

        if (self::$flushRegistered) {
            return;
        }

        self::$flushRegistered = true;

        // terminating() runs once the response has been sent. A fatal error never
        // reaches it, so the shutdown function is the fallback; flush() empties
        // the queue, so whichever runs second has nothing left to send.
        App::terminating(function () {
            $this->flush();
        });

        register_shutdown_function(function () {
            $this->flush();
        });
    }

    /**
     * @internal Used by tests and the queue tracking path to check whether
     * a capture is currently in flight.
     */
    public static function isCapturing(): bool
    {
        return self::$capturing;
    }

    /**
     * @return bool
     */
    public function isSkipEnvironment()
    {
        if (count(config('larabug.environments', [])) == 0) {
            return true;
        }

        if (in_array(App::environment(), config('larabug.environments', []))) {
            return false;
        }

        return true;
    }

    /**
     * @param string|null $id
     */
    private function setLastExceptionId(?string $id)
    {
        $this->lastExceptionId = $id;
    }

    /**
     * Get the last exception id given to us by the API.
     * @return string|null
     */
    public function getLastExceptionId()
    {
        return $this->lastExceptionId;
    }

    /**
     * @param Throwable $exception
     * @return array
     */
    public function getExceptionData(Throwable $exception)
    {
        $data = [];

        $console = App::runningInConsole();

        $data['environment'] = App::environment();
        $data['host'] = $console ? gethostname() : Request::server('SERVER_NAME');
        $data['method'] = $console ? 'CLI' : Request::method();
        $data['fullUrl'] = $console ? $this->consoleCommand() : Request::fullUrl();
        $data['exception'] = Str::limit($exception->getMessage() ?: '-', 5000);
        $data['error'] = Str::limit($exception->getTraceAsString(), 30000);
        $data['line'] = $exception->getLine();
        $data['file'] = $exception->getFile();
        $data['class'] = get_class($exception);
        $data['release'] = config('larabug.release', null);
        $data['fingerprint'] = Fingerprint::make($data['class'], $data['file'], $data['line'], $data['exception']);
        $data['storage'] = [
            'SERVER' => [
                'USER' => Request::server('USER'),
                'HTTP_USER_AGENT' => Request::server('HTTP_USER_AGENT'),
                'SERVER_PROTOCOL' => Request::server('SERVER_PROTOCOL'),
                'SERVER_SOFTWARE' => Request::server('SERVER_SOFTWARE'),
                'PHP_VERSION' => PHP_VERSION,
                'LARAVEL_VERSION' => App::version(),
            ],
            'OLD' => $this->filterVariables(Request::hasSession() ? Request::old() : []),
            // Only the names: the values carry the session id, which is enough
            // to take over the session of whoever hit the error.
            'COOKIE' => array_fill_keys(array_keys(Request::cookie()), '***'),
            'SESSION' => $this->filterVariables(Request::hasSession() ? Session::all() : []),
            'HEADERS' => $this->filterVariables(Request::header()),
            'PARAMETERS' => $this->filterVariables($this->filterParameterValues(Request::all()))
        ];

        $data['storage'] = array_filter($data['storage']);

        $count = config('larabug.lines_count');

        if ($count > 50) {
            $count = 12;
        }

        $lines = @file($data['file']);
        $data['executor'] = [];

        if ($lines !== false && count($lines) < $count) {
            $count = count($lines) - $data['line'];
        }

        if ($lines !== false) {
            for ($i = -1 * abs($count); $i <= abs($count); $i++) {
                $data['executor'][] = $this->getLineInfo($lines, $data['line'], $i);
            }
            $data['executor'] = array_values(array_filter($data['executor']));
        }

        // Get project version
        $data['project_version'] = config('larabug.project_version', null);

        // Add custom context data
        if (!empty(self::$customContext)) {
            $data['custom_data'] = self::$customContext;
            // Clear context after adding to exception
            self::$customContext = [];
        }

        // to make symfony exception more readable
        if ($data['class'] == 'Symfony\Component\Debug\Exception\FatalErrorException') {
            preg_match("~^(.+)' in ~", $data['exception'], $matches);
            if (isset($matches[1])) {
                $data['exception'] = $matches[1];
            }
        }

        return $data;
    }

    /**
     * The artisan command that was running, without its arguments: those can
     * hold anything, passwords included.
     *
     * @return string
     */
    private function consoleCommand()
    {
        $argv = Request::server('argv') ?: ($_SERVER['argv'] ?? null);

        if (! is_array($argv) || ! isset($argv[0])) {
            return 'cli';
        }

        return trim(basename($argv[0]).' '.($argv[1] ?? ''));
    }

    /**
     * @param array $parameters
     * @return array
     */
    public function filterParameterValues($parameters)
    {
        return $this->dataFilter->filterParameterValues($parameters);
    }

    /**
     * Determines whether the given parameter value should be filtered.
     *
     * @param mixed $value
     * @return bool
     */
    public function shouldParameterValueBeFiltered($value)
    {
        return $this->dataFilter->shouldParameterValueBeFiltered($value);
    }

    /**
     * @param $variables
     * @return array
     */
    public function filterVariables($variables)
    {
        return $this->dataFilter->filterVariables($variables);
    }

    /**
     * Gets information from the line.
     *
     * @param $lines
     * @param $line
     * @param $i
     *
     * @return array|void
     */
    private function getLineInfo($lines, $line, $i)
    {
        $currentLine = $line + $i;

        $index = $currentLine - 1;

        if (!array_key_exists($index, $lines)) {
            return;
        }

        return [
            'line_number' => $currentLine,
            'line' => Str::limit($lines[$index], 500),
        ];
    }

    /**
     * @param $exceptionClass
     * @return bool
     */
    public function isSkipException($exceptionClass)
    {
        return in_array($exceptionClass, config('larabug.except', []));
    }

    /**
     * @param $payload
     * @return \Psr\Http\Message\ResponseInterface|null
     */
    private function logError($payload)
    {
        return $this->client->report($payload);
    }

    /**
     * Only who it was, never their details: the server needs to tell users
     * apart, not to know their name or e-mail address.
     *
     * @return array|null
     */
    public function getUser()
    {
        if (! function_exists('auth') || ! (app() instanceof \Illuminate\Foundation\Application)) {
            return null;
        }

        foreach ($this->sessionGuards() as $guard) {
            if (! auth()->guard($guard)->check()) {
                continue;
            }

            /** @var \Illuminate\Contracts\Auth\Authenticatable $user */
            $user = auth()->guard($guard)->user();

            if ($user instanceof \LaraBug\Concerns\Larabugable) {
                return $user->toLarabug();
            }

            return [
                'id' => $user->getAuthIdentifier(),
                'type' => class_basename($user),
                'guard' => $guard,
            ];
        }

        return null;
    }

    /**
     * The default guard first, then every other session guard, so a user
     * logged in on a second guard (an admin area, say) is found as well.
     * Token guards are left out: checking those can mean a query per guard.
     *
     * @return array
     */
    private function sessionGuards()
    {
        $default = config('auth.defaults.guard');

        $guards = collect(config('auth.guards', []))
            ->filter(function ($guard) {
                return ($guard['driver'] ?? null) === 'session';
            })
            ->keys()
            ->reject(function ($name) use ($default) {
                return $name === $default;
            })
            ->values()
            ->all();

        return array_values(array_filter(array_merge([$default], $guards)));
    }
}
