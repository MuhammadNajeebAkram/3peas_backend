<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        // Prevent conflict with the generated unique default_slot column.
        DB::table('ai_models')
            ->where('provider', 'openai')
            ->update([
                'is_default' => false,
                'updated_at' => now(),
            ]);

        $models = [
            [
                'provider' => 'openai',
                'name' => 'GPT-5.6 Luna',
                'model_key' => 'gpt-5.6-luna',
                'description' => 'Economical model for straightforward, high-volume MCQ explanations.',
                'is_active' => true,
                'is_default' => false,
                'input_price_per_million' => 0.200000,
                'cached_input_price_per_million' => 0.020000,
                'output_price_per_million' => 1.200000,
                'currency' => 'USD',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'provider' => 'openai',
                'name' => 'GPT-5.4 Mini',
                'model_key' => 'gpt-5.4-mini',
                'description' => 'Recommended default for reliable English and Urdu MCQ explanations at moderate cost.',
                'is_active' => true,
                'is_default' => true,
                'input_price_per_million' => 0.750000,
                'cached_input_price_per_million' => 0.075000,
                'output_price_per_million' => 4.500000,
                'currency' => 'USD',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
            [
                'provider' => 'openai',
                'name' => 'GPT-5.6 Terra',
                'model_key' => 'gpt-5.6-terra',
                'description' => 'Higher-quality model for difficult mathematics, science and scenario-based questions.',
                'is_active' => true,
                'is_default' => false,
                'input_price_per_million' => 2.000000,
                'cached_input_price_per_million' => 0.200000,
                'output_price_per_million' => 12.000000,
                'currency' => 'USD',
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ],
        ];

        DB::table('ai_models')->upsert(
            $models,
            ['provider', 'model_key'],
            [
                'name',
                'description',
                'is_active',
                'is_default',
                'input_price_per_million',
                'cached_input_price_per_million',
                'output_price_per_million',
                'currency',
                'updated_at',
                'deleted_at',
            ]
        );
    }
}