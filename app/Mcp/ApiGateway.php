<?php

declare(strict_types=1);

namespace App\Mcp;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Runs an MCP tool's work through the app's own REST API, as the token's user.
 *
 * This is the whole security model of the connector in one place: a tool never touches models
 * or services directly, it calls the same /api/v1 endpoint the admin panel calls, so it gets
 * the same authentication, tenant scope, role checks, plan limits and validation — there is no
 * second code path that could drift from the first. Whatever the panel can't do, the MCP can't.
 *
 * Two extra guards:
 * - an allowlist of endpoints, so even a buggy tool can never reach platform administration,
 *   the updater, billing or team management;
 * - the sub-request is marked with a server-side attribute that no HTTP client can set, which
 *   is what lets RestrictApiTokens refuse the same token when used against the API directly.
 */
final class ApiGateway
{
    /** Request attribute marking a sub-request as coming from this gateway. */
    public const ATTRIBUTE = 'mcp.gateway';

    /**
     * Endpoints an MCP tool may reach, as [HTTP methods, path pattern].
     *
     * @var list<array{0: list<string>, 1: string}>
     */
    private const ALLOWED = [
        [['GET'], '#^agency$#'],
        [['GET'], '#^connectors$#'],
        [['GET', 'POST'], '#^clients$#'],
        [['GET', 'PUT', 'DELETE'], '#^clients/\d+$#'],
        [['GET', 'POST'], '#^sites$#'],
        [['GET', 'PUT', 'DELETE'], '#^sites/\d+$#'],
        [['GET'], '#^sites/\d+/(snapshot-periods|metric-catalog|data-sources|data-sources/coverage)$#'],
        [['GET', 'POST'], '#^sites/\d+/work-logs$#'],
        [['POST'], '#^sites/\d+/(data-sources|preview|sync|backfill|ai-template)$#'],
        [['POST'], '#^sites/\d+/connect/[a-z0-9_]+$#'],
        [['DELETE'], '#^work-logs/\d+$#'],
        [['PUT', 'DELETE'], '#^data-sources/\d+$#'],
        [['POST'], '#^data-sources/\d+/(test|discover)$#'],
        [['GET', 'POST'], '#^report-templates$#'],
        [['GET'], '#^report-templates/\d+$#'],
        [['GET', 'POST'], '#^report-definitions$#'],
        [['GET', 'PUT'], '#^report-definitions/\d+$#'],
        [['GET'], '#^reports$#'],
        [['POST'], '#^reports/generate$#'],
        [['GET', 'DELETE'], '#^reports/\d+$#'],
        [['POST'], '#^reports/\d+/(approve|send|narrative/regenerate|deliveries/retry-failed)$#'],
        [['PUT'], '#^reports/\d+/narrative$#'],
        [['GET', 'POST'], '#^reports/\d+/comments$#'],
        [['GET'], '#^reports/\d+/(deliveries|work-logs)$#'],
        [['GET', 'POST'], '#^schedules$#'],
        [['DELETE'], '#^schedules/\d+$#'],
        [['GET'], '#^(trends|upsell|anomalies)$#'],
        [['POST'], '#^anomalies/\d+/acknowledge$#'],
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Router $router,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(McpContext $context, string $path, array $query = []): ApiResponse
    {
        return $this->call($context, 'GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function call(McpContext $context, string $method, string $path, array $payload = []): ApiResponse
    {
        $method = strtoupper($method);
        $path = trim($path, '/');

        if (! $this->allowed($method, $path)) {
            // A tool asked for something outside the connector's reach. Never reachable from
            // assistant input alone (paths are built by tools), so this is a guard, not a feature.
            return new ApiResponse(403, ['message' => 'Esta operación no está disponible desde el conector.']);
        }

        $isRead = $method === 'GET';
        $request = Request::create(
            '/api/v1/'.$path,
            $method,
            $isRead ? $payload : [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => request()->ip() ?? '127.0.0.1'],
            $isRead ? null : (string) json_encode($payload),
        );
        $request->headers->set('Authorization', 'Bearer '.$context->bearer);
        $request->attributes->set(self::ATTRIBUTE, true);

        $previous = $this->app->make('request');
        $this->app->instance('request', $request);

        try {
            $response = $this->router->dispatch($request);
        } catch (HttpExceptionInterface $exception) {
            return new ApiResponse($exception->getStatusCode(), ['message' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return new ApiResponse(500, ['message' => 'Error interno de Imagina Reports.']);
        } finally {
            $this->app->instance('request', $previous);
        }

        $content = (string) $response->getContent();
        $decoded = $content === '' ? [] : json_decode($content, true);

        return new ApiResponse($response->getStatusCode(), is_array($decoded) ? $decoded : []);
    }

    private function allowed(string $method, string $path): bool
    {
        foreach (self::ALLOWED as [$methods, $pattern]) {
            if (in_array($method, $methods, true) && preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
