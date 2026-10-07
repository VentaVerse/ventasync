<?php

namespace App\Mcp\Concerns;

use App\Models\ApiClient;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

trait RunsSharedRules
{
    abstract protected function scope(): string;

    public function shouldRegister(HttpRequest $request): bool
    {
        $client = $request->user();

        return $client instanceof ApiClient && $client->hasScope($this->scope());
    }

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

        $data = (array) json_decode((string) json_encode($data), true);

        return $this->audited($client, 200, Response::structured($this->shape($data)));
    }

    protected function input(array $args): array
    {
        return array_filter($args, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

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
