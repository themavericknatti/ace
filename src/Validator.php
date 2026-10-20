<?php

namespace Ace;

class Validator
{
    /**
     * Input data to be validated.
     */
    protected array $data = [];

    /**
     * Validation rules mapped by attribute.
     */
    protected array $rules = [];

    /**
     * Custom error messages.
     */
    protected array $customMessages = [];

    /**
     * Custom attribute names for human-readable error messages.
     */
    protected array $customAttributes = [];

    /**
     * Grouped error messages [attribute => [messages]].
     */
    protected array $errors = [];

    /**
     * Sanitized and validated data fields.
     */
    protected array $validatedData = [];

    /**
     * Create a new Validator instance.
     *
     * @param array $data Input data
     * @param array $rules Validation rules
     * @param array $messages Custom messages
     * @param array $customAttributes Human-friendly attribute labels
     */
    public function __construct(
        array $data,
        array $rules,
        array $messages = [],
        array $customAttributes = []
    ) {
        $this->data = $data;
        $this->rules = $rules;
        $this->customMessages = $messages;
        $this->customAttributes = $customAttributes;
    }

    /**
     * Static factory method to create and execute validation.
     */
    public static function make(
        array $data,
        array $rules,
        array $messages = [],
        array $customAttributes = []
    ): self {
        $validator = new self($data, $rules, $messages, $customAttributes);
        $validator->validate();
        return $validator;
    }

    /**
     * Execute the validation checks against all rules.
     */
    public function validate(): bool
    {
        $this->errors = [];
        $this->validatedData = [];

        foreach ($this->rules as $attribute => $ruleSet) {
            $value = $this->getValue($attribute);
            $parsedRules = $this->parseRuleSet($ruleSet);

            $isRequired = false;
            foreach ($parsedRules as $r) {
                if ($r['name'] === 'required') {
                    $isRequired = true;
                    break;
                }
            }

            // If value is empty and not required, skip further checks
            $isEmpty = $value === null || $value === '' || (is_array($value) && empty($value));
            if ($isEmpty && !$isRequired) {
                continue;
            }

            foreach ($parsedRules as $ruleItem) {
                $ruleName = $ruleItem['name'];
                $params = $ruleItem['params'];

                $valid = $this->checkRule($attribute, $value, $ruleName, $params);
                if (!$valid) {
                    $this->addError($attribute, $ruleName, $params);
                }
            }

            if (!isset($this->errors[$attribute])) {
                $this->validatedData[$attribute] = $value;
            }
        }

        return empty($this->errors);
    }

    /**
     * Determine if validation failed.
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Determine if validation passed.
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Get all validation errors grouped by attribute.
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get only the first validation error for each attribute.
     */
    public function firstErrors(): array
    {
        $first = [];
        foreach ($this->errors as $attribute => $messages) {
            $first[$attribute] = $messages[0] ?? '';
        }
        return $first;
    }

    /**
     * Get first error for a given attribute.
     */
    public function first(string $attribute): ?string
    {
        return $this->errors[$attribute][0] ?? null;
    }

    /**
     * Check if a specific attribute has an error.
     */
    public function has(string $attribute): bool
    {
        return !empty($this->errors[$attribute]);
    }

    /**
     * Return only the validated and passed data.
     */
    public function validated(): array
    {
        return $this->validatedData;
    }

    /**
     * Check a single validation rule.
     */
    protected function checkRule(string $attribute, mixed $value, string $rule, array $params): bool
    {
        return match ($rule) {
            'required' => $value !== null && $value !== '' && (!is_array($value) || !empty($value)),
            'email'    => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'min'      => $this->validateMin($value, (float)($params[0] ?? 0)),
            'max'      => $this->validateMax($value, (float)($params[0] ?? INF)),
            'numeric'  => is_numeric($value),
            'integer', 'int' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'boolean', 'bool' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'url'      => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'date'     => strtotime((string)$value) !== false,
            'in'       => in_array((string)$value, $params, true),
            'not_in'   => !in_array((string)$value, $params, true),
            'confirmed' => $value === $this->getValue($attribute . '_confirmation') || $value === $this->getValue($attribute . 'Confirm'),
            'same'     => $value === $this->getValue($params[0] ?? ''),
            'different' => $value !== $this->getValue($params[0] ?? ''),
            'regex'    => (bool)preg_match($params[0] ?? '//', (string)$value),
            'alpha'    => ctype_alpha(str_replace(' ', '', (string)$value)),
            'alphanumeric', 'alpha_num' => ctype_alnum((string)$value),
            'unique'   => $this->validateUnique($attribute, $value, $params),
            'exists'   => $this->validateExists($attribute, $value, $params),
            default    => true,
        };
    }

    protected function validateMin(mixed $value, float $min): bool
    {
        if (is_numeric($value)) {
            return (float)$value >= $min;
        }
        if (is_array($value)) {
            return count($value) >= $min;
        }
        return mb_strlen((string)$value) >= $min;
    }

    protected function validateMax(mixed $value, float $max): bool
    {
        if (is_numeric($value)) {
            return (float)$value <= $max;
        }
        if (is_array($value)) {
            return count($value) <= $max;
        }
        return mb_strlen((string)$value) <= $max;
    }

    protected function validateUnique(string $attribute, mixed $value, array $params): bool
    {
        $table = $params[0] ?? null;
        $column = $params[1] ?? $attribute;
        $exceptId = $params[2] ?? null;
        $idColumn = $params[3] ?? 'id';

        if (!$table) {
            return true;
        }

        $db = Application::$app->db ?? null;
        if (!$db) {
            return true;
        }

        $sql = "SELECT 1 FROM `{$table}` WHERE `{$column}` = :val";
        $binds = [':val' => $value];

        if ($exceptId !== null && $exceptId !== '' && $exceptId !== 'NULL') {
            $sql .= " AND `{$idColumn}` != :except_id";
            $binds[':except_id'] = $exceptId;
        }

        $stmt = $db->prepare($sql . " LIMIT 1");
        $stmt->execute($binds);
        return $stmt->fetch() === false;
    }

    protected function validateExists(string $attribute, mixed $value, array $params): bool
    {
        $table = $params[0] ?? null;
        $column = $params[1] ?? 'id';

        if (!$table) {
            return true;
        }

        $db = Application::$app->db ?? null;
        if (!$db) {
            return true;
        }

        $stmt = $db->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}` = :val LIMIT 1");
        $stmt->execute([':val' => $value]);
        return $stmt->fetch() !== false;
    }

    /**
     * Add error message formatted with parameter substitutions.
     */
    protected function addError(string $attribute, string $rule, array $params): void
    {
        $label = $this->customAttributes[$attribute] ?? ucwords(str_replace('_', ' ', $attribute));
        
        // 1. Check custom messages
        $customKey = "{$attribute}.{$rule}";
        if (isset($this->customMessages[$customKey])) {
            $msg = $this->customMessages[$customKey];
        } elseif (isset($this->customMessages[$rule])) {
            $msg = $this->customMessages[$rule];
        } else {
            // 2. Default messages
            $msg = match ($rule) {
                'required' => "The {$label} field is required.",
                'email'    => "The {$label} must be a valid email address.",
                'min'      => "The {$label} must be at least " . ($params[0] ?? '') . " characters/units.",
                'max'      => "The {$label} may not be greater than " . ($params[0] ?? '') . " characters/units.",
                'numeric'  => "The {$label} must be a number.",
                'integer', 'int' => "The {$label} must be an integer.",
                'boolean', 'bool' => "The {$label} must be true or false.",
                'url'      => "The {$label} must be a valid URL.",
                'date'     => "The {$label} must be a valid date.",
                'in'       => "The selected {$label} is invalid. Allowed: " . implode(', ', $params),
                'not_in'   => "The selected {$label} is not allowed.",
                'confirmed' => "The {$label} confirmation does not match.",
                'same'     => "The {$label} and " . ($params[0] ?? '') . " must match.",
                'different' => "The {$label} and " . ($params[0] ?? '') . " must be different.",
                'regex'    => "The {$label} format is invalid.",
                'alpha'    => "The {$label} may only contain letters.",
                'alphanumeric', 'alpha_num' => "The {$label} may only contain letters and numbers.",
                'unique'   => "The {$label} has already been taken.",
                'exists'   => "The selected {$label} is invalid.",
                default    => "The {$label} is invalid.",
            };
        }

        $this->errors[$attribute][] = $msg;
    }

    /**
     * Parse pipe or array rule syntax: 'required|email|max:255' or ['required', 'email']
     */
    protected function parseRuleSet(string|array $ruleSet): array
    {
        $rules = is_array($ruleSet) ? $ruleSet : explode('|', (string)$ruleSet);
        $parsed = [];

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if ($rule === '') continue;

            if (str_contains($rule, ':')) {
                [$name, $paramStr] = explode(':', $rule, 2);
                $params = explode(',', $paramStr);
            } else {
                $name = $rule;
                $params = [];
            }

            $parsed[] = [
                'name'   => strtolower(trim($name)),
                'params' => array_map('trim', $params),
            ];
        }

        return $parsed;
    }

    /**
     * Retrieve value from data array supporting dot notation.
     */
    protected function getValue(string $attribute): mixed
    {
        if (array_key_exists($attribute, $this->data)) {
            return $this->data[$attribute];
        }

        $parts = explode('.', $attribute);
        $current = $this->data;
        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }

        return $current;
    }
}
