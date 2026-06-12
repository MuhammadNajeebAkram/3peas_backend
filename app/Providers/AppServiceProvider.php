<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use App\Notifications\ResetPassword as CustomResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    private array $auditColumnCache = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen('eloquent.creating: *', function (string $eventName, array $data) {
            $model = $data[0] ?? null;

            if (!$model instanceof Model) {
                return;
            }

            $userId = $this->currentAuditUserId();

            if (!$userId) {
                return;
            }

            if ($this->modelHasColumn($model, 'created_by') && !$model->getAttribute('created_by')) {
                $model->created_by = $userId;
            }

            if ($this->modelHasColumn($model, 'updated_by') && !$model->getAttribute('updated_by')) {
                $model->updated_by = $userId;
            }
        });

        Event::listen('eloquent.updating: *', function (string $eventName, array $data) {
            $model = $data[0] ?? null;

            if (!$model instanceof Model) {
                return;
            }

            $userId = $this->currentAuditUserId();

            if ($userId && $this->modelHasColumn($model, 'updated_by')) {
                $model->updated_by = $userId;
            }
        });

        ResetPasswordNotification::toMailUsing(function ($notifiable, $token) {
            return (new CustomResetPassword($token))->toMail($notifiable);
        });
    }

    private function modelHasColumn(Model $model, string $column): bool
    {
        $cacheKey = ($model->getConnectionName() ?: 'default') . '.' . $model->getTable() . '.' . $column;

        if (array_key_exists($cacheKey, $this->auditColumnCache)) {
            return $this->auditColumnCache[$cacheKey];
        }

        try {
            return $this->auditColumnCache[$cacheKey] = Schema::hasColumn($model->getTable(), $column);
        } catch (\Throwable $e) {
            return $this->auditColumnCache[$cacheKey] = false;
        }
    }

    private function currentAuditUserId(): ?int
    {
        $request = app()->bound('request') ? request() : null;

        $user = $request?->user()
            ?: auth('api')->user()
            ?: auth('web_api')->user()
            ?: auth()->user();

        return $user?->id ? (int) $user->id : null;
    }
}
