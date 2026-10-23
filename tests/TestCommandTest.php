<?php

namespace LaraBug\Tests;

use LaraBug\LaraBug;
use LaraBug\Tests\Mocks\LaraBugClient;

class TestCommandTest extends TestCase
{
    /** @test */
    public function it_detects_if_the_website_token_is_set()
    {
        $this->app['config']['larabug.login_key'] = '';

        $this->artisan('errorkit:test')
            ->expectsOutput('✗ [Errorkit] Could not find the website token, set FLOWRISE_TOKEN or EK_KEY in your .env')
            ->assertExitCode(0);

        $this->app['config']['larabug.login_key'] = 'test';

        $this->artisan('errorkit:test')
            ->expectsOutput('✓ [Errorkit] Found the website token')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_detects_that_its_running_in_the_correct_environment()
    {
        $this->app['config']['app.env'] = 'production';
        $this->app['config']['larabug.environments'] = [];

        $this->artisan('errorkit:test')
            ->expectsOutput('✗ [Errorkit] Environment (production) not allowed to send errors, set this in config/larabug.php')
            ->assertExitCode(0);

        $this->app['config']['larabug.environments'] = ['production'];

        $this->artisan('errorkit:test')
            ->expectsOutput('✓ [Errorkit] Correct environment found (' . config('app.env') . ')')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_detects_that_it_fails_to_send()
    {
        $this->artisan('errorkit:test')
            ->expectsOutput('✗ [Errorkit] Failed to send the exception (wrong environment, a duplicate within 60 seconds, rate limited or the server did not accept it)')
            ->assertExitCode(0);

        $this->app['config']['larabug.environments'] = [
            'testing',
        ];
        $this->app['larabug'] = new LaraBug($this->client = new LaraBugClient(
            'login_key',
            'project_key'
        ));

        $this->artisan('errorkit:test')
            ->expectsOutput('✓ [Errorkit] Sent exception with ID: '.LaraBugClient::RESPONSE_ID)
            ->assertExitCode(0);

        $this->assertEquals(LaraBugClient::RESPONSE_ID, $this->app['larabug']->getLastExceptionId());
    }
}
