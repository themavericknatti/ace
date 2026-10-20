<?php

namespace Ace;

class Controller
{
    public string $layout = 'main';
    public string $action = '';
    
    /**
     * @var Middleware[]
     */
    protected array $middlewares = [];

    /**
     * Active visual template override for this controller
     */
    protected ?string $template = null;

    /**
     * Set layout template for the controller
     */
    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }

    /**
     * Set explicit visual template override for this controller
     */
    public function setTemplate(?string $template): self
    {
        $this->template = $template;
        if (isset(Application::$app->templates)) {
            Application::$app->templates->setOverride($template);
        }
        return $this;
    }

    /**
     * Render a view from the controller
     *
     * @param string $view View name
     * @param array $params Data parameters
     * @param string|null $template Optional explicit template override for this view render
     */
    public function render(string $view, array $params = [], ?string $template = null): string
    {
        if ($template !== null && isset(Application::$app->templates)) {
            Application::$app->templates->setOverride($template);
        }
        return Application::$app->router->renderView($view, $params);
    }


    /**
     * Register route middleware on the controller
     */
    public function registerMiddleware(Middleware $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * Get all registered middlewares for this controller
     * 
     * @return Middleware[]
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Validate current request input using Ace\Validator.
     * Automatically flashes errors/old input and redirects back on failure (or returns 422 JSON for AJAX/API).
     *
     * @param array $rules Validation rules
     * @param array $messages Custom error messages
     * @param array $attributes Custom attribute names
     * @return array Validated input data
     */
    public function validate(array $rules, array $messages = [], array $attributes = []): array
    {
        $request = Application::$app->request;
        $data = $request ? $request->getBody() : $_POST;

        $validator = Validator::make($data, $rules, $messages, $attributes);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $response = Application::$app->response;
            $session = Application::$app->session;

            if ($request && $request->isAjax()) {
                $response->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => $errors,
                ], 422);
            }

            if ($session) {
                $session->setFlash('errors', $errors);
                $session->setFlash('old', $data);
                $session->setFlash('error', $validator->first(array_key_first($errors)) ?? 'Validation error.');
            }

            $response->back(302);
        }

        return $validator->validated();
    }

    /**
     * Return a JSON response.
     */
    public function json(array $data, int $statusCode = 200): void
    {
        Application::$app->response->json($data, $statusCode);
    }

    /**
     * Return a standardized success JSON response.
     */
    public function success(string $message = 'Success', array $data = [], int $statusCode = 200): void
    {
        $this->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], $statusCode);
    }

    /**
     * Return a standardized error JSON response.
     */
    public function error(string $message = 'Error', int $statusCode = 400, array $errors = []): void
    {
        $this->json([
            'status'  => 'error',
            'message' => $message,
            'errors'  => $errors,
        ], $statusCode);
    }

    /**
     * Redirect to another URL with optional flash data.
     */
    public function redirect(string $url, int $statusCode = 302, array $flashData = []): void
    {
        Application::$app->response->redirect($url, $statusCode, $flashData);
    }

    /**
     * Redirect back to the previous page.
     */
    public function back(int $statusCode = 302, array $flashData = []): void
    {
        Application::$app->response->back($statusCode, $flashData);
    }
}

