<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Workbench\App\Models\Product;
use Workbench\Database\Factories\UserFactory;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        UserFactory::new()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Sample products for the Workbench shop; replace freely.
        foreach ([
            ['emoji' => '🍓', 'name' => '딸기 마카롱', 'description' => '새콤달콤 딸기 크림을 듬뿍 넣은 마카롱', 'price' => 1000],
            ['emoji' => '🧸', 'name' => '곰돌이 쿠키', 'description' => '버터 향 가득한 곰돌이 모양 쿠키 세트', 'price' => 3500],
            ['emoji' => '🍰', 'name' => '조각 케이크', 'description' => '부드러운 생크림 조각 케이크', 'price' => 6000],
            ['emoji' => '🎁', 'name' => '디저트 선물 상자', 'description' => '인기 디저트를 골고루 담은 선물 상자', 'price' => 10000],
        ] as $product) {
            Product::create($product);
        }
    }
}
