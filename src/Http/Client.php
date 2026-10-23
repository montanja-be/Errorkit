<?php

namespace LaraBug\Http;

use GuzzleHttp\ClientInterface;

class Client
{
    /** @var ClientInterface|null */
    protected $client;

    /** @var string */
    protected $login;

    /** @var string */
    protected $project;

    /**
     * @param string $login
     * @param string $project
     * @param ClientInterface|null $client
     */
    public function __construct(string $login, string $project, ?ClientInterface $client = null)
    {
        $this->login = $login;
        $this->project = $project;
        $this->client = $client;
    }

    /**
     * @param array $exception
     * @return \GuzzleHttp\Promise\PromiseInterface|\Psr\Http\Message\ResponseInterface|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function report($exception)
    {
        try {
            return $this->getGuzzleHttpClient()->request('POST', config('larabug.server'), [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->login,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'Errorkit-Package'
                ],
                'json' => array_merge([
                    'project' => $this->project,
                    'additional' => [],
                ], $exception),
                'verify' => config('larabug.verify_ssl'),
                'http_errors' => false,
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,  // Preserve POST method on redirects
                    'referer' => true,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => false
                ],
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getGuzzleHttpClient()
    {
        if (! isset($this->client)) {
            // Short on purpose: a report is sent from the request or worker that
            // failed, and a slow server must not make that one slower still.
            $this->client = new \GuzzleHttp\Client([
                'connect_timeout' => (float) config('larabug.connect_timeout', 2),
                'timeout' => (float) config('larabug.timeout', 5),
            ]);
        }

        return $this->client;
    }

    /**
     * @param ClientInterface $client
     * @return $this
     */
    public function setGuzzleHttpClient(ClientInterface $client)
    {
        $this->client = $client;

        return $this;
    }
}
