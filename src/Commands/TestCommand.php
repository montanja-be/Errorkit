<?php

namespace LaraBug\Commands;

use Exception;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    protected $signature = 'errorkit:test {exception?}';

    protected $description = 'Generate a test exception and send it to Errorkit';

    public function handle()
    {
        try {
            /** @var \LaraBug\LaraBug $laraBug */
            $laraBug = app('larabug');

            if (config('larabug.login_key')) {
                $this->info('✓ [Errorkit] Found the website token');
            } else {
                $this->error('✗ [Errorkit] Could not find the website token, set FLOWRISE_TOKEN or EK_KEY in your .env');
            }

            if (in_array(config('app.env'), config('larabug.environments'))) {
                $this->info('✓ [Errorkit] Correct environment found (' . config('app.env') . ')');
            } else {
                $this->error('✗ [Errorkit] Environment (' . config('app.env') . ') not allowed to send errors, set this in config/larabug.php');
            }

            $response = $laraBug->handle(
                $this->generateException()
            );

            if (isset($response->id)) {
                $this->info('✓ [Errorkit] Sent exception with ID: '.$response->id);
            } elseif (is_object($response)) {
                $this->info('✓ [Errorkit] Sent exception!');
            } else {
                $this->error('✗ [Errorkit] Failed to send the exception (wrong environment, a duplicate within '.config('larabug.sleep').' seconds, rate limited or the server did not accept it)');
            }
        } catch (\Exception $ex) {
            $this->error("✗ [Errorkit] {$ex->getMessage()}");
        }
    }

    public function generateException(): ?Exception
    {
        try {
            throw new Exception($this->argument('exception') ?? 'This is a test exception from the Errorkit console');
        } catch (Exception $ex) {
            return $ex;
        }
    }
}
