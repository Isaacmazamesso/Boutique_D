<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'             => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'session.timeout'  => \App\Http\Middleware\CheckSessionTimeout::class,
        ]);

        // Doit s'exécuter AVANT Authenticate : ce dernier résout le guard sanctum et met
        // à jour last_used_at, ce qui rendrait le contrôle d'inactivité toujours "à jour".
        // Sans ceci, Laravel réordonne les middlewares selon $middlewarePriority et fait
        // toujours passer Authenticate en premier, quel que soit l'ordre déclaré en route.
        // Note : la liste de priorité référence le contrat AuthenticatesRequests, pas la
        // classe concrète Authenticate — sinon le "before" ne matche rien et l'ajout se
        // retrouve silencieusement en fin de liste (donc après, pas avant).
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\CheckSessionTimeout::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toujours renvoyer du JSON pour les routes API
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*');
        });
    })->create();
