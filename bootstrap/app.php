<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\ResolveCurrentBusiness;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
    'business.context' => ResolveCurrentBusiness::class,
    'platform.admin' => RequirePlatformAdmin::class,
]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isApi = fn(Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen(fn(Request $request, Throwable $e) => $isApi($request));
        $exceptions->dontReport([ApiException::class]);

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (!$isApi($request)) {
                return null;
            }

            $errors = [];
            $errorCodes = [];

// Get the actual failed Laravel validation rules.
            $failedRules = $e->validator->failed();

            $ruleCodes = [
                'Required'  => 'required',
                'Email'     => 'invalid_email',
                'Min'       => 'too_short',
                'Max'       => 'too_long',
                'Confirmed' => 'confirmation_mismatch',
                'Unique'    => 'already_exists',
                'In'        => 'invalid_choice',
                'Regex'     => 'invalid_format',
                'Numeric'   => 'invalid_number',
                'Integer'   => 'invalid_integer',
                'Date'      => 'invalid_date',
            ];

            foreach ($e->errors() as $field => $messages) {
                $rules = array_keys($failedRules[$field] ?? []);
                $ruleIndex = 0;

                foreach ($messages as $message) {
                    if (preg_match('/^⟦([a-zA-Z0-9_]+)⟧(.*)$/us', $message, $matches)) {
                        // Preserve existing custom validation codes.
                        $errorCodes[$field][] = $matches[1];
                        $errors[$field][] = $matches[2];
                    } else {
                        // Assign a machine-readable code to Laravel validation errors.
                        $rule = $rules[$ruleIndex] ?? null;

                        $errorCodes[$field][] = $rule !== null
                        ? ($ruleCodes[$rule] ?? strtolower($rule))
                        : 'invalid';

                        $errors[$field][] = $message;
                    }

                    $ruleIndex++;
                }
            }

            return ApiResponse::error(
                'VALIDATION_FAILED',
                'Please correct the highlighted fields.',
                422,
                null,
                $errors,
                $errorCodes
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request)  ?ApiResponse::error('UNAUTHENTICATED', 'Please log in to continue.', 401) : null;
        });

        $exceptions->render(function (AuthorizationException | AccessDeniedHttpException $e, Request $request) use ($isApi) {
            return $isApi($request)  ?ApiResponse::error('FORBIDDEN', 'You are not allowed to do this.', 403) : null;
        });

        // The same answer for "does not exist" and "belongs to another business": nothing to discover.
        $exceptions->render(function (ModelNotFoundException | NotFoundHttpException $e, Request $request) use ($isApi) {
            return $isApi($request)  ?ApiResponse::error('NOT_FOUND', 'Not found.', 404) : null;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($isApi) {
            return $isApi($request)  ?ApiResponse::error('METHOD_NOT_ALLOWED', 'This method is not allowed here.', 405) : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            if (!$isApi($request)) {
                return null;
            }

            $retry = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return ApiResponse::error('TOO_MANY_REQUESTS', 'Too many attempts. Please wait and try again.', 429, ['retry_after_seconds' => $retry])
                ->withHeaders($e->getHeaders());
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            return $isApi($request)
             ?ApiResponse::error('HTTP_ERROR', 'The request could not be completed.', $e->getStatusCode())
            : null;
        });

        // Anything else: never show internals. (With APP_DEBUG=true on a developer machine, Laravel's own page is used.)
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (!$isApi($request) || app()->hasDebugModeEnabled()) {
                return null;
            }

            return ApiResponse::error('SERVER_ERROR', 'Something went wrong on our side. Please try again later.', 500);
        });
    })->create();
