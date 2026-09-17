<?php

namespace Database\Seeders;

use App\Models\AiProvider;
use Illuminate\Database\Seeder;

class AiProviderSeeder extends Seeder
{
    public function run(): void
    {
        AiProvider::firstOrCreate(['key' => 'openai'], ['name' => 'OpenAI', 'is_active' => true]);
        AiProvider::firstOrCreate(['key' => 'gemini'], ['name' => 'Google Gemini', 'is_active' => false]);
    }
}
