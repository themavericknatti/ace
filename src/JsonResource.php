<?php

namespace Ace;

/**
 * JsonResource — Transform models and collections into structured JSON responses.
 *
 * Inherit from this class and override `toArray($request)` to customize fields.
 */
class JsonResource
{
    /**
     * The resource instance being wrapped.
     */
    protected mixed $resource;

    /**
     * Create a new resource instance.
     */
    public function __construct(mixed $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Transform the resource into an array. Override in subclasses.
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof Model) {
            return $this->resource->toArray();
        }

        if (is_array($this->resource)) {
            return $this->resource;
        }

        return (array) $this->resource;
    }

    /**
     * Resolve the resource to an envelope wrapped array.
     */
    public function resolve(Request $request): array
    {
        return [
            'data' => $this->toArray($request)
        ];
    }

    /**
     * Convert the resource representation to a clean JSON string response.
     */
    public function toResponse(Request $request): string
    {
        $response = Application::$app->response;
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        return json_encode($this->resolve($request));
    }

    /**
     * Create a new anonymous resource collection.
     */
    public static function collection(array $resources): array
    {
        $request = Application::$app->request;
        return array_map(function ($resource) use ($request) {
            return (new static($resource))->toArray($request);
        }, $resources);
    }

    /**
     * Return a collection wrapped in a data envelope as a JSON response.
     */
    public static function collectionResponse(array $resources): string
    {
        $request = Application::$app->request;
        $response = Application::$app->response;
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        return json_encode([
            'data' => static::collection($resources)
        ]);
    }
}
