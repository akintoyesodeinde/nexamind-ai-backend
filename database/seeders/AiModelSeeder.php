<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\AI\Models\AiModel;
use App\Domain\AI\Models\AiProvider;
use Illuminate\Database\Seeder;

class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        /** @var AiProvider $mockProvider */
        $mockProvider = AiProvider::query()->updateOrCreate(
            ['tenant_id' => null, 'provider_key' => 'mock'],
            [
                'display_name' => 'NexaMind Grounded Mock Gateway (Local/CI)',
                'endpoint_url' => null,
                'is_active' => true,
            ],
        );

        AiModel::query()->updateOrCreate(
            ['ai_provider_id' => $mockProvider->id, 'alias' => 'default-chat'],
            [
                'provider_model_name' => 'nexamind-grounded-v1',
                'capability' => 'chat',
                'max_context_tokens' => 16000,
                'input_cost_per_1k_usd' => '0.000150',
                'output_cost_per_1k_usd' => '0.000600',
                'is_active' => true,
            ],
        );

        AiModel::query()->updateOrCreate(
            ['ai_provider_id' => $mockProvider->id, 'alias' => 'reasoning-chat'],
            [
                'provider_model_name' => 'nexamind-grounded-pro-v1',
                'capability' => 'chat',
                'max_context_tokens' => 32000,
                'input_cost_per_1k_usd' => '0.001250',
                'output_cost_per_1k_usd' => '0.005000',
                'is_active' => true,
            ],
        );

        AiModel::query()->updateOrCreate(
            ['ai_provider_id' => $mockProvider->id, 'alias' => 'default-embedding'],
            [
                'provider_model_name' => 'nexamind-embed-1536',
                'capability' => 'embedding',
                'max_context_tokens' => 8192,
                'input_cost_per_1k_usd' => '0.000020',
                'output_cost_per_1k_usd' => '0.000000',
                'is_active' => true,
            ],
        );
    }
}
