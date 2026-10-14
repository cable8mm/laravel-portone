<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $description
 * @property string $emoji
 * @property int $price
 */
class Product extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'description', 'emoji', 'price'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
        ];
    }
}
