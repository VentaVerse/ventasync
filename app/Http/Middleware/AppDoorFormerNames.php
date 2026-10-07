<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Phone apps released before VentaCart was renamed from Venta keep speaking "venta" on /api.
class AppDoorFormerNames
{
    private const PARAMETERS = ['channel', 'platform'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isFormerDoor($request)) {
            return $next($request);
        }

        $route = $request->route();
        if ($route !== null) {
            foreach (self::PARAMETERS as $name) {
                if ($route->parameter($name) === 'venta') {
                    $route->setParameter($name, 'ventacart');
                }
            }
        }
        foreach (self::PARAMETERS as $name) {
            if ($request->input($name) === 'venta') {
                $request->merge([$name => 'ventacart']);
            }
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setData($this->formerNames($response->getData()));
        }

        return $response;
    }

    private function isFormerDoor(Request $request): bool
    {
        return $request->is('api/*') && ! $request->is('api/v1/*') && ! $request->is('api/app/*');
    }

    private function formerNames(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->rename($value);
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $out->{$this->rename((string) $key)} = $this->formerNames($item);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->formerNames($item), $value);
        }

        return $value;
    }

    private function rename(string $text): string
    {
        return preg_replace(['/(?<![A-Za-z])venta_cart(?![a-z])/', '/(?<![A-Za-z])ventacart(?![a-z])/'], 'venta', $text);
    }
}
