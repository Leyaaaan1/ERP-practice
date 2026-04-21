<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Force all responses to JSON for API-only project
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        /**
         * VALIDATION ERRORS (422)
         * Laravel throws this when $request->validate() fails.
         * Return all validation messages in a consistent format.
         */
        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        });

        /**
         * MODEL NOT FOUND (404)
         * When you use route model binding (e.g., SalesOrder $salesOrder)
         * and the ID doesn't exist, Laravel throws this automatically.
         */
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            $model = class_basename($e->getModel());
            return response()->json([
                'success' => false,
                'message' => "{$model} not found.",
            ], 404);
        });

        /**
         * ROUTE NOT FOUND (404)
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'API endpoint not found.',
            ], 404);
        });

        /**
         * BUSINESS LOGIC ERRORS (422)
         * Our services throw plain \Exception with descriptive messages.
         * We catch them here and return a consistent JSON error response.
         *
         * Examples:
         *   - "Insufficient stock for 'Wireless Mouse'. Requested: 10, Available: 3"
         *   - "Purchase Order PO-20250115-001 cannot be received. Current status: received."
         */
        $exceptions->render(function (\Exception $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });
    })->create();
