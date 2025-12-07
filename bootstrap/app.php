<?php


use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\HandleCors;
use App\Http\Middleware\RefreshAuthTokenMiddleware;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\EnsureEmailsVerified;
use App\Http\Middleware\EnsurePaymentVerified;
use App\Http\Middleware\EnsureStudySessionVerified;
use App\Http\Middleware\VerifyUserSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',        
        health: '/up',
        then: function () {
            Route::prefix('web_api')
                ->name('web_api.')
                ->group(base_path('routes/web_api.php'));
            
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
        $middleware->append(HandleCors::class);
        //$middleware->append(RefreshAuthTokenMiddleware::class);
        $middleware->alias(['token.refresh' => RefreshAuthTokenMiddleware::class,
    ]);     
        $middleware->alias(['verified' => EnsureEmailsVerified::class]);
        $middleware->alias(['paymentVerified' => EnsurePaymentVerified::class]);
        $middleware->alias(['studySessionVerified' => EnsureStudySessionVerified::class]);

       
        
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
