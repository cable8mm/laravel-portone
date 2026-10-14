<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $webhook_id
 * @property string $type
 * @property string|null $payment_id
 * @property Carbon|null $created_at
 */
#[Fillable(['webhook_id', 'type', 'payment_id'])]
class WebhookLog extends Model {}
