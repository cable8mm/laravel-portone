<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

/**
 * PortOne 결제 조회 응답에서 개인정보와 카드·계좌 식별 정보를 가린다.
 *
 * 키 이름이 일치하면 깊이와 상관없이 값을 [REDACTED]로 바꾸고, 키는 그대로 둬서 응답 구조를 유지한다.
 * 기준은 PortOne 결제 응답 문서의 customer, method, cashReceipt 필드와, T-030 live 응답에서 찾은
 * PG 원본 응답·접속 정보·웹훅 전달 기록이다(PRODUCT_SPEC FR-079).
 */
final class PaymentResponseRedactor
{
    public const REDACTED = '[REDACTED]';

    /**
     * 값을 가리는 키 이름.
     */
    public const KEYS = [
        // 고객 개인정보
        'name',
        'birthYear',
        'birthMonth',
        'birthDay',
        'gender',
        'email',
        'phoneNumber',
        'address',
        'zipcode',
        'customerIdentityNumber',
        // 카드 식별 정보
        'number',
        'bin',
        'approvalNumber',
        // 계좌 식별 정보
        'accountNumber',
        'remitteeName',
        'remitterName',
        'depositorName',
        // PG 원본 응답(JSON 문자열이라 안쪽 키로 가릴 수 없음: 카드번호·승인번호 포함)
        'pgResponse',
        // 접속·전달 기록
        'ipAddress',
        'userAgent',
        'receiptUrl',
        'webhooks',
    ];

    /**
     * 응답의 복사본에서 KEYS에 해당하는 값을 가려 반환한다.
     *
     * @param  array<mixed>  $response  PortOne 결제 조회 응답
     * @return array<mixed> 가린 응답
     */
    public function redact(array $response): array
    {
        foreach ($response as $key => $value) {
            if (is_string($key) && in_array($key, self::KEYS, true)) {
                $response[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $response[$key] = $this->redact($value);
            }
        }

        return $response;
    }
}
