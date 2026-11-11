<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 웹훅 기록 Eloquent 모델. webhook-id 하나당 1건이다.
 *
 * 검증된 웹훅은 어떤 효과보다 먼저 기록되고, 처리에 성공하면 그 효과와 같은 트랜잭션에서 processed_at이 채워진다.
 * processed_at이 있는 webhook-id가 다시 오면 아무것도 하지 않는다. 처리에 실패하면 failed_at·failure가 채워지고
 * 다음 전달 때 다시 처리한다. model:prune은 보관 기간이 지난 처리 완료 기록만 지운다.
 *
 * @property string $webhook_id
 * @property string $type
 * @property string $body 받은 그대로의 요청 본문
 * @property int $attempts 이 webhook-id를 받은 횟수
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $failed_at
 * @property string|null $failure 마지막 처리 실패의 예외 클래스와 메시지
 */
class Webhook extends Model
{
    use MassPrunable;

    protected $table = 'portone_webhooks';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * model:prune이 지울 레코드: portone.webhook_retention_days일보다 먼저 처리된 기록만.
     *
     * 처리되지 않았거나 실패한 기록은 지우지 않는다.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('portone.webhook_retention_days');

        return static::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', now()->subDays(is_numeric($days) ? (int) $days : 90));
    }
}
