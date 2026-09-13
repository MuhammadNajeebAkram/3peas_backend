<?php

use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\AuthenticateJwtCookieGuard;
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
use App\Http\Middleware\CheckPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $middleware->alias(['jwt.cookie' => AttachJwtFromCookie::class]);
        $middleware->alias(['jwt.auth' => AuthenticateJwtCookieGuard::class]);
        $middleware->alias(['permission' => CheckPermission::class]);

       
       
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            // Only unmatched API routes; exclude model/resource 404s and browser noise.
            if ($request->route() === null && $request->is('api/*', 'web_api/*')) {
                Log::channel('route_errors')->warning('Route not found', [
                    'method' => $request->method(),
                    'path' => substr($request->path(), 0, 2048),
                ]);
            }

            return null;
        });
    })->create();
