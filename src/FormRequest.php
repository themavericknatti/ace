<?php

namespace Ace;

use Ace\Application;
use ArrayAccess;

abstract class FormRequest implements ArrayAccess
{
    /**
     * The underlying Request instance.
     */
    protected Request $request;

    /**
     * The validator instance.
     */
    protected ?Validator $validator = null;

    /**
     * Validated fields.
     */
    protected array $validatedData = [];

    /**
     * Create a new FormRequest instance.
     */
    public function __construct(?Request $request = null)
    {
        $this->request = $request ?? Application::$app->request;
    }

    /**
     * Determine if user is authorized to make this request.
     * Override in child classes (e.g. check permissions or auth state).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Define the validation rules for the request.
     *
     * @return array [field => 'required|email|max:255']
     */
    abstract public function rules(): array;

    /**
     * Define custom validation error messages.
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Define custom attribute display names.
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Execute authorization and validation.
     * If validation fails:
     * - AJAX/JSON: responds with 422 JSON
     * - Web: flashes errors and old input, redirects back
     *
     * @return array Validated input data
     */
    public function validate(): array
    {
        // 1. Authorization check
        if (!$this->authorize()) {
            $this->failedAuthorization();
        }

        // 2. Run validator
        $data = $this->request->getBody();
        $this->validator = Validator::make(
            $data,
            $this->rules(),
            $this->messages(),
            $this->attributes()
        );

        if ($this->validator->fails()) {
            $this->failedValidation();
        }

        $this->validatedData = $this->validator->validated();
        return $this->validatedData;
    }

    /**
     * Get validated data. Runs validate() if not yet executed.
     */
    public function validated(): array
    {
        if (empty($this->validatedData) && $this->validator === null) {
            return $this->validate();
        }
        return $this->validatedData;
    }

    /**
     * Handle failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        $response = Application::$app->response;
        if ($this->request->isAjax()) {
            $response->json(['message' => 'This action is unauthorized.'], 403);
        } else {
            $response->setStatusCode(403);
            if (Application::$app->view) {
                echo Application::$app->view->render('errors/403', [
                    'message' => 'Unauthorized action'
                ]);
            } else {
                echo '403 Forbidden';
            }
            if (php_sapi_name() !== 'cli') {
                exit;
            }
        }
    }

    /**
     * Handle failed validation.
     */
    protected function failedValidation(): void
    {
        $errors = $this->validator->errors();
        $response = Application::$app->response;
        $session = Application::$app->session;

        if ($this->request->isAjax()) {
            $response->json([
                'message' => 'The given data was invalid.',
                'errors'  => $errors,
            ], 422);
        }

        // Web Request: flash errors and old input, then redirect back
        if ($session) {
            $session->setFlash('errors', $errors);
            $session->setFlash('old', $this->request->getBody());
            $session->setFlash('error', $this->validator->first(array_key_first($errors)) ?? 'Validation error.');
        }

        $response->back(302);
    }

    /**
     * Get the underlying Request instance.
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get an input value from the request.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request->get($key, $default);
    }

    /**
     * ArrayAccess implementation.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->request->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->request->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Immutable request input
    }

    public function offsetUnset(mixed $offset): void
    {
        // Immutable request input
    }

    /**
     * Magic getter to access request inputs like $request->email.
     */
    public function __get(string $name): mixed
    {
        return $this->request->get($name);
    }
}
