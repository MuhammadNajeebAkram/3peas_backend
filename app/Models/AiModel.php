<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiModel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ai_provider_id', 'name', 'model_key', 'description', 'is_active', 'is_default', 'supports_images',
        'input_price_per_million', 'cached_input_price_per_million',
        'output_price_per_million', 'currency',
    ];

    protected $hidden = ['default_slot'];

    protected $casts = [
        'supports_images' => 'boolean',
        'is_active' => 'boolean', 'is_default' => 'boolean',
        'input_price_per_million' => 'decimal:6',
        'cached_input_price_per_million' => 'decimal:6',
        'output_price_per_million' => 'decimal:6',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function pricingSnapshot(): array
    {
        return $this->only([
            'input_price_per_million', 'cached_input_price_per_million',
            'output_price_per_million', 'currency',
        ]);
    }
}
