<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // ゲーム本体とは別の管理ツール(tool/)のビュー・ルートを読み込む。
            View::addNamespace('tool', base_path('tool/views'));
            Route::middleware('web')->group(base_path('tool/routes.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Codespace等のリバースプロキシ経由でアクセスした際、asset()/url()が
        // 内部アドレス(127.0.0.1等)ではなく外部からアクセス可能なホスト名で
        // URLを生成できるようにするため、プロキシからのX-Forwarded-*ヘッダーを信頼する。
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
