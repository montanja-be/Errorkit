<?php

namespace LaraBug\Tests;

use Exception;
use Carbon\Carbon;
use LaraBug\LaraBug;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use LaraBug\Tests\Mocks\LaraBugClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LaraBugTest extends TestCase
{
    /** @var LaraBug */
    protected $laraBug;

    /** @var Mocks\LaraBugClient */
    protected $client;

    public function setUp(): void
    {
        parent::setUp();

        \LaraBug\Support\Throttle::reset();

        $this->laraBug = new LaraBug($this->client = new LaraBugClient(
            'login_key',
            'project_key'
        ));
    }

    /** @test */
    public function is_will_not_crash_if_larabug_returns_error_bad_response_exception()
    {
        $this->laraBug = new LaraBug($this->client = new \LaraBug\Http\Client(
            'login_key',
            'project_key'
        ));

        //
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->client->setGuzzleHttpClient(new Client([
            'handler' => MockHandler::createWithMiddleware([
                new Response(500, [], '{}'),
            ]),
        ]));

        $this->assertFalse($this->laraBug->handle(new Exception('is_will_not_crash_if_larabug_returns_error_bad_response_exception')));
    }

    /** @test */
    public function is_will_not_crash_if_larabug_returns_normal_exception()
    {
        $this->laraBug = new LaraBug($this->client = new \LaraBug\Http\Client(
            'login_key',
            'project_key'
        ));

        //
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->client->setGuzzleHttpClient(new Client([
            'handler' => MockHandler::createWithMiddleware([
                new \Exception(),
            ]),
        ]));

        $this->assertFalse($this->laraBug->handle(new Exception('is_will_not_crash_if_larabug_returns_normal_exception')));
    }

    /** @test */
    public function it_can_skip_exceptions_based_on_class()
    {
        $this->app['config']['larabug.except'] = [];

        $this->assertFalse($this->laraBug->isSkipException(NotFoundHttpException::class));

        $this->app['config']['larabug.except'] = [
            NotFoundHttpException::class,
        ];

        $this->assertTrue($this->laraBug->isSkipException(NotFoundHttpException::class));
    }

    /** @test */
    public function it_can_skip_exceptions_based_on_environment()
    {
        $this->app['config']['larabug.environments'] = [];

        $this->assertTrue($this->laraBug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = ['production'];

        $this->assertTrue($this->laraBug->isSkipEnvironment());

        $this->app['config']['larabug.environments'] = ['testing'];

        $this->assertFalse($this->laraBug->isSkipEnvironment());
    }

    /** @test */
    public function it_can_get_formatted_exception_data()
    {
        $data = $this->laraBug->getExceptionData(new Exception(
            'it_can_get_formatted_exception_data'
        ));

        $this->assertSame('testing', $data['environment']);
        // Tests run in the console, where there is no request to describe.
        $this->assertSame(gethostname(), $data['host']);
        $this->assertSame('CLI', $data['method']);
        $this->assertNotEmpty($data['fullUrl']);
        $this->assertSame('it_can_get_formatted_exception_data', $data['exception']);

        $this->assertCount(14, $data);
        $this->assertSame(40, strlen($data['fingerprint']));
    }

    /** @test */
    public function it_filters_the_data_based_on_the_configuration()
    {
        $this->assertContains('*password*', $this->app['config']['larabug.blacklist']);

        $data = [
            'password' => 'testing',
            'not_password' => 'testing',
            'not_password2' => [
                'password' => 'testing',
            ],
            'not_password_3' => [
                'nah' => [
                    'password' => 'testing',
                ],
            ],
            'Password' => 'testing',
        ];


        $this->assertContains('***', $this->laraBug->filterVariables($data));
//        $this->assertArrayHasKey('not_password', $this->laraBug->filterVariables($data));
//        $this->assertArrayNotHasKey('password', $this->laraBug->filterVariables($data)['not_password2']);
//        $this->assertArrayNotHasKey('password', $this->laraBug->filterVariables($data)['not_password_3']['nah']);
//        $this->assertArrayNotHasKey('Password', $this->laraBug->filterVariables($data));
    }

    /** @test */
    public function it_can_report_an_exception_to_larabug()
    {
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->laraBug->handle(new Exception('it_can_report_an_exception_to_larabug'));

        $this->client->assertRequestsSent(1);
    }

    /** @test */
    public function it_bundles_duplicates_and_sends_the_count_with_the_next_report()
    {
        $this->app['config']['larabug.environments'] = ['testing'];
        $this->app['config']['larabug.sleep'] = 60;

        Carbon::setTestNow('2026-10-23 10:00:00');

        for ($i = 1; $i <= 3; $i++) {
            $this->laraBug->handle($this->exceptionFromTheSameLine('Order '.$i.' not found'));
        }

        $this->client->assertRequestsSent(1);
        $this->assertSame(1, $this->client->getRequests()[0]['exception']['occurrences']);

        Carbon::setTestNow('2026-10-23 10:01:01');

        $this->laraBug->handle($this->exceptionFromTheSameLine('Order 4 not found'));

        $this->client->assertRequestsSent(2);
        $this->assertSame(3, $this->client->getRequests()[1]['exception']['occurrences']);

        Carbon::setTestNow();
    }

    /** @test */
    public function it_drops_reports_over_the_rate_limit_and_reports_how_many()
    {
        $this->app['config']['larabug.environments'] = ['testing'];
        $this->app['config']['larabug.sleep'] = 0;
        $this->app['config']['larabug.rate_limit.per_minute'] = 2;

        for ($i = 1; $i <= 5; $i++) {
            $this->laraBug->handle(new Exception('rate limited'));
        }

        $this->client->assertRequestsSent(2);

        $this->app['config']['larabug.rate_limit.per_minute'] = 0;

        $this->laraBug->handle(new Exception('rate limited'));

        $this->client->assertRequestsSent(3);
        $this->assertSame(3, $this->client->getRequests()[2]['exception']['dropped']);
    }

    /** @test */
    public function it_backs_off_when_the_server_asks_for_it()
    {
        $this->app['config']['larabug.environments'] = ['testing'];
        $this->app['config']['larabug.sleep'] = 0;

        $guzzle = new \GuzzleHttp\Handler\MockHandler([
            new Response(429, ['Retry-After' => '120'], '{}'),
            new Response(200, [], '{"id":"after"}'),
        ]);

        $this->laraBug = new LaraBug($client = new \LaraBug\Http\Client('login_key', 'project_key'));
        $client->setGuzzleHttpClient(new Client(['handler' => \GuzzleHttp\HandlerStack::create($guzzle)]));

        $this->assertFalse($this->laraBug->handle(new Exception('first')));
        $this->assertFalse($this->laraBug->handle(new Exception('during the back off')));
        $this->assertCount(1, $guzzle);

        Carbon::setTestNow(now()->addSeconds(121));

        $response = $this->laraBug->handle(new Exception('after the back off'));

        $this->assertSame('after', $response->id);

        Carbon::setTestNow();
    }

    /** @test */
    public function it_normalises_messages_for_the_fingerprint()
    {
        $this->assertSame(
            \LaraBug\Support\Fingerprint::make('E', base_path('app/A.php'), 10, 'No query results for model [Order] 42'),
            \LaraBug\Support\Fingerprint::make('E', base_path('app/A.php'), 10, 'No query results for model [Order] 43')
        );

        $this->assertNotSame(
            \LaraBug\Support\Fingerprint::make('E', base_path('app/A.php'), 10, 'Something'),
            \LaraBug\Support\Fingerprint::make('E', base_path('app/A.php'), 11, 'Something')
        );

        $this->assertSame('app/A.php', \LaraBug\Support\Fingerprint::relativeFile('/home/forge/site/releases/20261023/app/A.php'));
    }

    /** @test */
    public function it_only_sends_who_the_user_is()
    {
        $this->app['config']['larabug.environments'] = ['testing'];

        $this->actingAs((new \Illuminate\Foundation\Auth\User())->forceFill(['id' => 7, 'email' => 'someone@example.com']));

        $this->laraBug->handle(new Exception('user'));

        $this->assertSame(['id' => 7, 'type' => 'User', 'guard' => 'web'], $this->client->getRequests()[0]['user']);
    }

    private function exceptionFromTheSameLine(string $message): Exception
    {
        return new Exception($message);
    }
}
