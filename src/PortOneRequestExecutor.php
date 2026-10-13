<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\PortonePhp\Http\RequestExecutor;
use GuzzleHttp\Client;

class PortOneRequestExecutor extends RequestExecutor
{
    public function __construct(
        #[\SensitiveParameter] string $apiSecret,
        private readonly string $baseUrl,
    ) {
        parent::__construct($apiSecret);
    }

    protected function createClient(): Client
    {
        return new Client([
            'base_uri' => $this->baseUrl,
            'connect_timeout' => 10,
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }
}
