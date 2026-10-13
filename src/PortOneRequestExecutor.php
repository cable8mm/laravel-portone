<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\PortonePhp\Http\RequestExecutor;
use GuzzleHttp\Client;

/**
 * PortOne API 주소를 적용한 HTTP 실행기.
 *
 * base_url 설정이 비었으면 PortOne 기본 주소를 사용한다.
 */
class PortOneRequestExecutor extends RequestExecutor
{
    /**
     * @param  string  $apiSecret  PortOne API Secret (로그·예외에 노출 금지)
     * @param  string  $baseUrl  PortOne API 기본 주소
     */
    public function __construct(
        #[\SensitiveParameter] string $apiSecret,
        private readonly string $baseUrl,
    ) {
        parent::__construct($apiSecret);
    }

    /**
     * 타임아웃과 base_uri를 적용한 HTTP 클라이언트를 만든다.
     *
     * @return Client PortOne 요청용 Guzzle 클라이언트
     */
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
