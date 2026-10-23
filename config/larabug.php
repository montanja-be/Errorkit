<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DSN (Data Source Name)
    |--------------------------------------------------------------------------
    |
    | You can use a single DSN string to configure Errorkit instead of
    | individual keys. Format: https://login-key:project-key@host/path
    | Example: https://abc123:def456@projecten.montanja.be/api/log
    |
    | If DSN is set, it will take precedence over individual keys below.
    |
    */
    'dsn' => env('EK_DSN'),

    /*
    |--------------------------------------------------------------------------
    | Login key
    |--------------------------------------------------------------------------
    |
    | The token of the website on projecten.montanja.be. Every Munia site
    | already has it as FLOWRISE_TOKEN for its heartbeat, so by default
    | there is nothing to configure.
    |
    */
    'login_key' => env('EK_KEY', env('FLOWRISE_TOKEN', '')),

    /*
    |--------------------------------------------------------------------------
    | Project key
    |--------------------------------------------------------------------------
    |
    | Sent along with every report. projecten.montanja.be identifies the site
    | by its token alone, so this only labels the reports.
    |
    */
    'project_key' => env('EK_PROJECT_KEY', 'munia'),

    /*
    |--------------------------------------------------------------------------
    | Register the exception handler
    |--------------------------------------------------------------------------
    |
    | Report exceptions automatically, so an application does not have to wire
    | LaraBug into its own exception handler. Disable this if you want to call
    | LaraBug::handle() yourself and decide per exception whether to send it.
    |
    */
    'register_exception_handler' => env('EK_REGISTER_EXCEPTION_HANDLER', true),

    /*
    |--------------------------------------------------------------------------
    | Environment setting
    |--------------------------------------------------------------------------
    |
    | This setting determines if the exception should be send over or not.
    |
    */
    'environments' => [
        'production',
    ],

    /*
    |--------------------------------------------------------------------------
    | Project version
    |--------------------------------------------------------------------------
    |
    | Set the project version, default: null.
    | For git repository: shell_exec("git log -1 --pretty=format:'%h' --abbrev-commit")
    |
    */
    'project_version' => null,

    /*
    |--------------------------------------------------------------------------
    | Lines near exception
    |--------------------------------------------------------------------------
    |
    | How many lines to show near exception line. The more you specify the bigger
    | the displayed code will be. Max value can be 50, will be defaulted to
    | 12 if higher than 50 automatically.
    |
    */
    'lines_count' => 12,

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicates
    |--------------------------------------------------------------------------
    |
    | The same error (same fingerprint) is sent at most once per this many
    | seconds. Repeats in between are counted, and that count is sent along
    | with the next report of the error. 0 sends every occurrence.
    |
    */
    'sleep' => env('EK_SLEEP', 60),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | At most this many reports per minute leave the site, whatever the
    | errors. Reports over the limit are dropped and only counted; the count
    | goes along with the next report that does go out.
    |
    | When the server answers 429 or 5xx, or does not answer at all, the site
    | stops sending for the Retry-After the server gave, or `backoff` seconds.
    |
    */
    'rate_limit' => [
        'per_minute' => env('EK_RATE_LIMIT', 10),
    ],

    'backoff' => env('EK_BACKOFF', 60),

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | Deferred: during a web request reports are sent after the response has
    | gone out, so a visitor never waits on the server. Console commands and
    | queue workers always send right away.
    |
    */
    'deferred' => env('EK_DEFERRED', true),

    'connect_timeout' => env('EK_CONNECT_TIMEOUT', 2),

    'timeout' => env('EK_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Skip exceptions
    |--------------------------------------------------------------------------
    |
    | List of exceptions to skip sending.
    |
    */
    'except' => [
        'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
    ],

    /*
    |--------------------------------------------------------------------------
    | Key filtering
    |--------------------------------------------------------------------------
    |
    | Filter out these variables before sending them to LaraBug
    |
    */
    'blacklist' => [
        '*authorization*',
        '*password*',
        '*token*',
        '*auth*',
        '*verification*',
        '*credit_card*',
        'cardToken', // mollie card token
        '*cvv*',
        '*iban*',
        '*name*',
        '*email*',
        '*secret*',
        '*cookie*',
        '*session*',
        '*phone*',
        '*address*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Release git hash
    |--------------------------------------------------------------------------
    |
    |
    */
    'release' => env('EK_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Server setting
    |--------------------------------------------------------------------------
    |
    | This setting allows you to change the server.
    |
    */
    'server' => env('EK_SERVER', 'https://projecten.montanja.be/api/log'),

    /*
    |--------------------------------------------------------------------------
    | Verify SSL setting
    |--------------------------------------------------------------------------
    |
    | Enables / disables the SSL verification when sending exceptions
    | Never turn SSL verification off on production instances
    |
    */
    'verify_ssl' => env('EK_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Job Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue job monitoring behavior.
    |
    */
    'jobs' => [
        /*
        | Enable or disable queue job tracking
        | Off by default: projecten.montanja.be only receives errors. A failed
        | job is still reported, through the exception handler.
        */
        'track_jobs' => env('EK_TRACK_JOBS', false),

        /*
        |--------------------------------------------------------------------------
        | Dynamic Batching (Automatic Load-Based)
        |--------------------------------------------------------------------------
        |
        | Batching automatically activates when job dispatch rate exceeds a 
        | threshold. During low traffic, events are sent immediately (no delay).
        | During high traffic, events are buffered and sent in batches.
        |
        | This provides the best of both worlds: zero delay for low traffic
        | and automatic protection during spikes.
        |
        */

        /*
        | Jobs per minute threshold to enable batching
        | When dispatch rate exceeds this, batching activates automatically
        | Default: 10 jobs/minute
        */
        'auto_batch_threshold' => env('EK_AUTO_BATCH_THRESHOLD', 10),

        /*
        | Jobs per minute threshold to disable batching
        | When rate drops below this (and cooldown passes), batching deactivates
        | Default: 5 jobs/minute
        */
        'auto_batch_disable_threshold' => env('EK_AUTO_BATCH_DISABLE', 5),

        /*
        | Cooldown period (in minutes) before disabling batching
        | After rate drops below threshold, wait this long before disabling
        | Prevents rapid enable/disable cycling
        | Default: 5 minutes
        */
        'auto_batch_cooldown' => env('EK_AUTO_BATCH_COOLDOWN', 5),

        /*
        | Number of events to buffer before auto-flushing to API
        | Higher values = fewer API calls but more memory usage
        | Recommended: 50-100 for most applications
        | Default: 50
        */
        'batch_size' => env('EK_BATCH_SIZE', 50),

        /*
        | Auto-flush interval in seconds
        | Events are sent when buffer is full OR after this many seconds
        | Ensures events aren't held indefinitely in low-traffic scenarios
        | Default: 30 seconds
        */
        'flush_interval' => env('EK_FLUSH_INTERVAL', 30),

        /*
        | Maximum retry attempts for failed API calls
        | Each retry uses exponential backoff (100ms, 200ms, 300ms)
        | Default: 3 retries
        */
        'max_retries' => env('EK_MAX_RETRIES', 3),

        /*
        |--------------------------------------------------------------------------
        | Smart Tracking (Reduce Volume)
        |--------------------------------------------------------------------------
        |
        | Control which job lifecycle events are tracked. Most users only care
        | about failures, so consider disabling processing/completed tracking
        | for high-volume queues.
        |
        */

        /*
        | Track when jobs START processing
        | Useful for debugging stuck jobs but doubles API calls
        | Default: false (most users don't need this)
        */
        'track_processing' => env('EK_TRACK_PROCESSING', false),

        /*
        | Track when jobs COMPLETE successfully
        | Useful for metrics but high volume for busy queues
        | Consider using sample_rate instead of disabling completely
        | Default: true
        */
        'track_completed' => env('EK_TRACK_COMPLETED', true),

        /*
        | Track when jobs FAIL
        | HIGHLY RECOMMENDED - this is the main value of LaraBug
        | Default: true (always track failures)
        */
        'track_failed' => env('EK_TRACK_FAILED', true),

        /*
        |--------------------------------------------------------------------------
        | Sampling Configuration
        |--------------------------------------------------------------------------
        |
        | For high-volume queues, you may not need 100% of successful completions.
        | Sampling tracks a percentage of completed jobs while still tracking
        | ALL failures at 100%.
        |
        */

        /*
        | Sample rate for successful job completions (0.0 - 1.0)
        | 1.0 = track 100% of successful jobs (default)
        | 0.5 = track 50% of successful jobs
        | 0.1 = track 10% of successful jobs
        | 0.0 = don't track successful jobs
        | 
        | Note: Failures are ALWAYS tracked at 100% regardless of this setting
        */
        'sample_rate' => env('EK_SAMPLE_RATE', 1.0),

        /*
        |--------------------------------------------------------------------------
        | Queue Filtering
        |--------------------------------------------------------------------------
        */

        /*
        | Only track jobs on specific queues (empty array = all queues)
        | Example: ['high-priority', 'emails']
        | Environment: EK_ONLY_QUEUES=high-priority,emails
        */
        'only_queues' => array_filter(explode(',', env('EK_ONLY_QUEUES', ''))),

        /*
        | Ignore jobs on specific queues
        | Example: ['low-priority', 'notifications']
        | Environment: EK_IGNORE_QUEUES=low-priority,notifications
        */
        'ignore_queues' => array_filter(explode(',', env('EK_IGNORE_QUEUES', ''))),

        /*
        | Ignore specific job classes
        | Example: [\App\Jobs\UnimportantJob::class]
        */
        'ignore_jobs' => [],

        /*
        |--------------------------------------------------------------------------
        | Advanced Settings
        |--------------------------------------------------------------------------
        */

        /*
        | Maximum payload size in bytes (payloads larger will be truncated)
        */
        'max_payload_size' => env('EK_JOBS_MAX_PAYLOAD_SIZE', 10000),

        /*
        | Report SDK errors back to LaraBug (useful for debugging)
        | Default: false
        */
        'report_sdk_errors' => env('EK_REPORT_SDK_ERRORS', false),

        /*
        | Report buffer flush errors back to LaraBug (useful for debugging)
        | Default: false
        */
        'report_buffer_errors' => env('EK_REPORT_BUFFER_ERRORS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | CVE / Dependency vulnerability scanning
    |--------------------------------------------------------------------------
    |
    | When enabled, the package periodically scans your composer.lock against
    | LaraBug's CVE database and surfaces findings as Issues on your project.
    | Independent of error and queue tracking — you can install LaraBug just
    | for vulnerability scanning if you want.
    |
    */
    'cve' => [
        /*
        | Master toggle. Off by default: projecten.montanja.be does not receive
        | scans (yet).
        |
        | Turning this on alone does not start scanning: the project also has to
        | have CVE scanning enabled in LaraBug. Until it does, the server answers
        | 403 and the client backs off for an hour, so leaving this on costs at
        | most one request an hour.
        |
        | Only package names, versions and a hash of composer.lock ever leave the
        | app. No source, no paths, no env values.
        */
        'enabled' => env('EK_CVE_ENABLED', false),

        /*
        | How scans get triggered:
        |   'request'  — piggyback on incoming HTTP requests. Detects composer.lock
        |                changes ~immediately after deploy. Zero scheduler needed.
        |   'schedule' — only via the daily scheduled command. Requires schedule:run.
        |   'both'     — request-piggyback is the primary trigger; the scheduler
        |                acts as a safety net for apps with no inbound traffic.
        | Default: both.
        */
        'trigger' => env('EK_CVE_TRIGGER', 'both'),

        /*
        | Scheduler frequency. 'daily' | 'hourly' | 'twice-daily', or a custom
        | cron expression. Only used when trigger includes 'schedule' or 'both'.
        */
        'schedule' => env('EK_CVE_SCHEDULE', 'daily'),

        /*
        | Hours to wait before re-scanning an unchanged composer.lock when
        | triggered by request piggyback. Hash-change is detected immediately
        | regardless; this only applies to the heartbeat for stale stacks.
        */
        'request_throttle_hours' => env('EK_CVE_REQUEST_THROTTLE_HOURS', 24),

        /*
        | Include `packages-dev` from composer.lock in the scan.
        | Off by default — production deps are what matters in deployed apps.
        */
        'include_dev' => env('EK_CVE_INCLUDE_DEV', false),

        /*
        | Path to composer.lock. Defaults to base_path('composer.lock').
        */
        'lock_path' => env('EK_CVE_LOCK_PATH'),

        /*
        | Environment label sent alongside the scan (matches `app.env` by default).
        */
        'environment' => env('EK_CVE_ENVIRONMENT'),
    ],
];
