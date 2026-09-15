<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiModel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'provider', 'name', 'model_key', 'description', 'is_active', 'is_default',
        'input_price_per_million', 'cached_input_price_per_million',
        'output_price_per_million', 'currency',
    ];

    protected $hidden = ['default_slot'];

    protected $casts = [
        'is_active' => 'boolean', 'is_default' => 'boolean',
        'input_price_per_million' => 'decimal:6',
        'cached_input_price_per_million' => 'decimal:6',
        'output_price_per_million' => 'decimal:6',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }

    public function pricingSnapshot(): array
    {
        return $this->only([
            'input_price_per_million', 'cached_input_price_per_million',
            'output_price_per_million', 'currency',
        ]);
    }
}
