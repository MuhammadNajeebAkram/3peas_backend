<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    public const SUPPORTED = ['openai', 'gemini'];

    protected $fillable = ['key', 'name', 'is_active', 'settings'];

    protected $casts = ['is_active' => 'boolean', 'settings' => 'array'];

    protected $appends = ['is_supported', 'is_configured'];

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function getIsSupportedAttribute(): bool
    {
        return in_array($this->key, self::SUPPORTED, true);
    }

    public function getIsConfiguredAttribute(): bool
    {
        return $this->is_supported && filled(config('services.'.$this->key.'.api_key'));
    }

    public function setting(string $key, int $default): int
    {
        return (int) ($this->settings[$key] ?? config('services.'.$this->key.'.'.$key, $default));
    }
}
