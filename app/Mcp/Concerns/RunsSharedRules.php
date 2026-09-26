<?php

namespace App\Mcp\Concerns;

use App\Models\ApiClient;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

/**
 * How every tool reaches the data: through the shared rule classes the
 * partner API also runs (App\Actions and each extension's Actions), called
 * directly with no request in between. One definition of each figure and
 * each change; the tool adds its scope check, its messages and its call log.
 */
trait RunsSharedRules
{
    /** The scope this tool needs; also what shouldRegister() checks. */
    abstract protected function scope(): string;

    /**
     * Listed only for an application whose token carries the scope, so an
     * assistant never sees a tool it cannot run.
     */
    public function shouldRegister(HttpRequest $request): bool
    {
        $client = $request->user();

        return $client instanceof ApiClient && $client->hasScope($this->scope());
    }

    /**
     * Run one of the shared business rules directly, as the application: the
     * same rule the partner API runs, with no request in between. The same
     * scope check, the same messages and the same call log for every tool.
     *
     * @param  \Closure(): array<string, mixed>  $rule
     */
    protected function runRule(\Closure $rule): Response|ResponseFactory
    {
        $client = request()->user();
        if (! $client instanceof ApiClient || ! $client->hasScope($this->scope())) {
            return $this->audited($client, 403, Response::error('This application is missing the scope ' . $this->scope() . '.'));
        }

        try {
            $data = $rule();
        } catch (ValidationException $e) {
            return $this->audited($client, 422, Response::error('Arguments rejected: ' . implode(' ', array_map(fn ($m) => implode(' ', $m), $e->errors()))));
        } catch (\App\Support\Api\Refused $e) {
            return $this->audited($client, $e->status, Response::error($e->getMessage()));
        } catch (Throwable $e) {
            report($e);

            return $this->audited($client, 500, Response::error('The ERP could not do that: ' . $e->getMessage()));
        }

        // The answer as the door would have sent it: the same JSON a caller
        // of the partner API receives, before the tool shapes it.
        $data = (array) json_decode((string) json_encode($data), true);

        return $this->audited($client, 200, Response::structured($this->shape($data)));
    }

    /**
     * The tool's arguments as a rule takes them: blanks dropped, the way a
     * request would have dropped them.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function input(array $args): array
    {
        return array_filter($args, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Override to trim or annotate the API's answer before it is returned. */
    protected function shape(array $data): array
    {
        return $data;
    }

    private function audited(?ApiClient $client, int $status, Response|ResponseFactory $response): Response|ResponseFactory
    {
        \App\Services\Api\ApiCallLog::record($client, 'MCP', 'mcp:' . $this->name(), $this->scope(), $status, request()->ip());

        return $response;
    }
}
