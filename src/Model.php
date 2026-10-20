<?php

namespace Ace;

use Ace\Exceptions\ModelNotFoundException;

abstract class Model implements \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    public const RULE_REQUIRED = 'required';
    public const RULE_EMAIL = 'email';
    public const RULE_MIN = 'min';
    public const RULE_MAX = 'max';
    public const RULE_MATCH = 'match';
    public const RULE_UNIQUE = 'unique';
    public const RULE_EXISTS = 'exists';
    public const RULE_DATE = 'date';
    public const RULE_NUMERIC = 'numeric';
    public const RULE_IN = 'in';
    public const RULE_BOOLEAN = 'boolean';
    public const RULE_URL = 'url';
    public const RULE_CONFIRMED = 'confirmed';

    protected array $attributes = [];
    public array $errors = [];

    // =========================================================================
    // Guard Configuration — Override in child models
    // =========================================================================

    /**
     * Fields that ARE allowed for mass assignment.
     * If non-empty, only these fields pass through loadData/fill/create.
     * Leave empty to allow all fields (except $guarded).
     */
    protected array $fillable = [];

    /**
     * Fields that are NEVER mass-assignable.
     * Always blocked from loadData/fill/create even if $fillable is empty.
     */
    protected array $guarded = [];

    /**
     * Fields that skip input sanitization on save.
     * Use for rich-text / HTML editor columns (e.g. 'body', 'bio_html').
     */
    protected array $rawFields = [];

    /**
     * Whether the input guard is enabled (auto-sanitize on save).
     * Set to false in a model to disable automatic sanitization.
     */
    protected bool $inputGuarded = true;

    /**
     * Indicates if the model should be automatically timestamped (created_at, updated_at).
     */
    public bool $timestamps = true;

    /**
     * The name of the "created at" column.
     */
    public const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     */
    public const UPDATED_AT = 'updated_at';

    /**
     * Whether soft deletes are enabled for this model.
     */
    protected bool $softDelete = false;

    /**
     * The name of the "deleted at" column.
     */
    public const DELETED_AT = 'deleted_at';

    /**
     * Attributes that should be hidden for serialization (e.g. password, remember_token).
     */
    protected array $hidden = [];

    /**
     * Attributes that should be visible for serialization.
     */
    protected array $visible = [];

    /**
     * Execute a callback within a database transaction.
     */
    public static function transaction(callable $callback): mixed
    {
        $db = Application::$app->db;
        if (!$db) {
            throw new \Exception("Database connection not established.");
        }
        return $db->transaction($callback);
    }

    /**
     * Start a new fluent query builder instance for the model
     */
    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::class);
    }

    abstract public static function tableName(): string;
    abstract public function primaryKey(): string;
    abstract public function rules(): array;

    /**
     * Get class attributes dynamically mapping to DB table columns if not overridden
     */
    public function attributes(): array
    {
        return [];
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }

        // Dynamically resolve relationship methods if defined (e.g. $user->posts)
        if (method_exists($this, $name)) {
            $relation = $this->{$name}();
            if ($relation instanceof \Ace\Relations\Relation) {
                $results = $relation->getResults();
                $this->attributes[$name] = $results;
                return $results;
            }
            $this->attributes[$name] = $relation;
            return $relation;
        }

        return null;

    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || method_exists($this, $name);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    // =========================================================================
    // ArrayAccess, Countable, IteratorAggregate & JsonSerializable Implementation
    // Allows $model['property'] syntax alongside $model->property
    // =========================================================================

    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string) $offset) || isset($this->{(string) $offset});
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->__unset((string) $offset);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->attributes);
    }

    public function jsonSerialize(): mixed
    {
        return method_exists($this, 'toArray') ? $this->toArray() : $this->attributes;
    }

    // =========================================================================
    // Output Guard — Safe / Raw Access
    // =========================================================================

    /**
     * Get an attribute value escaped for safe HTML output.
     *
     *   <p><?= $user->safe('name') ?></p>
     */
    public function safe(string $key): string
    {
        return Guard::output($this->attributes[$key] ?? null);
    }

    /**
     * Get an attribute value with NO escaping (raw database value).
     * Use only when you explicitly trust the content (e.g. rendering rich HTML).
     *
     *   <div><?= $post->raw('body') ?></div>
     */
    public function raw(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    // =========================================================================
    // Data Loading with Mass Assignment Guard
    // =========================================================================

    /**
     * Populate model attributes from request/array data.
     * Applies mass-assignment guard ($fillable / $guarded).
     */
    public function loadData(array $data): void
    {
        $data = Guard::filterMassAssignment($data, $this->fillable, $this->guarded);

        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * Force-populate model attributes from database records, bypassing mass-assignment guard.
     */
    public function forceFill(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    /**
     * Perform validations defined in model rules
     */
    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->rules() as $attribute => $rules) {
            $value = $this->{$attribute} ?? null;
            
            if (is_string($rules)) {
                $rules = explode('|', $rules);
            }
            
            foreach ($rules as $rule) {
                $ruleName = $rule;
                $ruleData = [];
                
                if (is_string($rule)) {
                    if (str_contains($rule, ':')) {
                        [$name, $paramStr] = explode(':', $rule, 2);
                        $ruleName = $name;
                        
                        if ($ruleName === self::RULE_MIN) {
                            $ruleData = ['min' => (int)$paramStr];
                        } elseif ($ruleName === self::RULE_MAX) {
                            $ruleData = ['max' => (int)$paramStr];
                        } elseif ($ruleName === self::RULE_MATCH) {
                            $ruleData = ['match' => $paramStr];
                        } elseif ($ruleName === self::RULE_UNIQUE) {
                            $parts = explode(',', $paramStr);
                            $ruleData = [
                                'table' => $parts[0],
                                'column' => $parts[1] ?? $attribute
                            ];
                        } elseif ($ruleName === self::RULE_EXISTS) {
                            $parts = explode(',', $paramStr);
                            $ruleData = [
                                'table' => $parts[0],
                                'column' => $parts[1] ?? 'id'
                            ];
                        } elseif ($ruleName === self::RULE_IN) {
                            $ruleData = ['values' => explode(',', $paramStr)];
                        }
                    }
                } elseif (is_array($rule)) {
                    $ruleName = $rule[0];
                    $ruleData = $rule;
                }

                if ($ruleName === self::RULE_REQUIRED && (is_null($value) || $value === '')) {
                    $this->addErrorForRule($attribute, self::RULE_REQUIRED);
                }

                if ($ruleName === self::RULE_EMAIL && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addErrorForRule($attribute, self::RULE_EMAIL);
                }

                if ($ruleName === self::RULE_MIN && !empty($value) && strlen((string)$value) < ($ruleData['min'] ?? 0)) {
                    $this->addErrorForRule($attribute, self::RULE_MIN, $ruleData);
                }

                if ($ruleName === self::RULE_MAX && !empty($value) && strlen((string)$value) > ($ruleData['max'] ?? INF)) {
                    $this->addErrorForRule($attribute, self::RULE_MAX, $ruleData);
                }

                if ($ruleName === self::RULE_MATCH && $value !== $this->{$ruleData['match'] ?? ''}) {
                    $ruleData['matchLabel'] = $ruleData['match'] ?? '';
                    $this->addErrorForRule($attribute, self::RULE_MATCH, $ruleData);
                }

                if ($ruleName === self::RULE_UNIQUE && !empty($value)) {
                    $tableName = $ruleData['table'] ?? null;
                    if (!$tableName) {
                        $className = $ruleData['class'] ?? static::class;
                        $tableName = $className::tableName();
                    }
                    $uniqueAttr = $ruleData['column'] ?? $ruleData['attribute'] ?? $attribute;
                    $db = Application::$app->db;

                    if ($db) {
                        $sql = "SELECT * FROM `$tableName` WHERE `$uniqueAttr` = :$uniqueAttr";
                        $params = [":$uniqueAttr" => $value];
                        
                        $pk = $this->primaryKey();
                        $pkValue = $this->{$pk} ?? null;
                        if (!empty($pkValue)) {
                            $sql .= " AND `$pk` != :pk_val";
                            $params[":pk_val"] = $pkValue;
                        }

                        $statement = $db->prepare($sql);
                        $statement->execute($params);
                        if ($statement->fetch()) {
                            $this->addErrorForRule($attribute, self::RULE_UNIQUE, ['field' => $attribute]);
                        }
                    }
                }

                if ($ruleName === self::RULE_EXISTS && !empty($value)) {
                    $existsTable = $ruleData['table'] ?? null;
                    $existsColumn = $ruleData['column'] ?? 'id';
                    $db = Application::$app->db;

                    if ($db && $existsTable) {
                        $stmt = $db->prepare("SELECT 1 FROM `$existsTable` WHERE `$existsColumn` = :val LIMIT 1");
                        $stmt->execute([':val' => $value]);
                        if (!$stmt->fetch()) {
                            $this->addErrorForRule($attribute, self::RULE_EXISTS, [
                                'table' => $existsTable,
                                'column' => $existsColumn
                            ]);
                        }
                    }
                }

                if ($ruleName === self::RULE_DATE && !empty($value)) {
                    if (strtotime($value) === false) {
                        $this->addErrorForRule($attribute, self::RULE_DATE);
                    }
                }

                if ($ruleName === self::RULE_NUMERIC && !empty($value) && !is_numeric($value)) {
                    $this->addErrorForRule($attribute, self::RULE_NUMERIC);
                }

                if ($ruleName === self::RULE_IN && !empty($value)) {
                    $allowed = $ruleData['values'] ?? [];
                    if (!in_array($value, $allowed, true)) {
                        $this->addErrorForRule($attribute, self::RULE_IN, [
                            'values' => implode(', ', $allowed)
                        ]);
                    }
                }

                if ($ruleName === self::RULE_BOOLEAN) {
                    $acceptable = [true, false, 0, 1, '0', '1', 'true', 'false'];
                    if (!in_array($value, $acceptable, true)) {
                        $this->addErrorForRule($attribute, self::RULE_BOOLEAN);
                    }
                }

                if ($ruleName === self::RULE_URL && !empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addErrorForRule($attribute, self::RULE_URL);
                }

                if ($ruleName === self::RULE_CONFIRMED) {
                    $confirmValue = $this->{$attribute . '_confirmation'} ?? $this->{$attribute . 'Confirm'} ?? null;
                    if ($value !== $confirmValue) {
                        $this->addErrorForRule($attribute, self::RULE_CONFIRMED);
                    }
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * Add standard or custom error messages
     */
    private function addErrorForRule(string $attribute, string $ruleName, array $params = []): void
    {
        $message = $this->errorMessages()[$ruleName] ?? '';
        foreach ($params as $key => $value) {
            $message = str_replace("{{$key}}", $value, $message);
        }
        $this->errors[$attribute][] = $message;
    }

    public function addError(string $attribute, string $message): void
    {
        $this->errors[$attribute][] = $message;
    }

    public function hasError(string $attribute): bool
    {
        return !empty($this->errors[$attribute]);
    }

    public function hasErrors(?string $attribute = null): bool
    {
        if ($attribute !== null) {
            return !empty($this->errors[$attribute]);
        }
        return !empty($this->errors);
    }

    public function getFirstError(string $attribute): string
    {
        return $this->errors[$attribute][0] ?? '';
    }

    /**
     * Get all validation errors grouped by attribute.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get only the first validation error for each attribute.
     *
     * @return array An associative array of [attribute => firstError]
     */
    public function getFirstErrors(): array
    {
        $firstErrors = [];
        foreach ($this->errors as $attribute => $messages) {
            if (!empty($messages)) {
                $firstErrors[$attribute] = $messages[0];
            }
        }
        return $firstErrors;
    }

    /**
     * Get a flat list of all validation error messages.
     *
     * @return array A flat indexed array of all error message strings
     */
    public function getErrorMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $attributeErrors) {
            foreach ($attributeErrors as $message) {
                $messages[] = $message;
            }
        }
        return $messages;
    }

    private function errorMessages(): array
    {
        return [
            self::RULE_REQUIRED => 'This field is required',
            self::RULE_EMAIL => 'This field must be a valid email address',
            self::RULE_MIN => 'Min length of this field must be {min}',
            self::RULE_MAX => 'Max length of this field must be {max}',
            self::RULE_MATCH => 'This field must be the same as {matchLabel}',
            self::RULE_UNIQUE => 'Record with this {field} already exists',
            self::RULE_EXISTS => 'The selected {column} does not exist in {table}',
            self::RULE_DATE => 'This field must be a valid date',
            self::RULE_NUMERIC => 'This field must be a number',
            self::RULE_IN => 'This field must be one of: {values}',
            self::RULE_BOOLEAN => 'This field must be true or false',
            self::RULE_URL => 'This field must be a valid URL',
            self::RULE_CONFIRMED => 'The confirmation does not match',
        ];
    }

    /**
     * Active Record Save (Insert or Update)
     */
    public function save(): bool
    {
        $tableName = static::tableName();
        $primaryKey = $this->primaryKey();
        $db = Application::$app->db;

        if (!$db) {
            return false;
        }

        // Get table columns to filter attributes
        $columns = $this->attributes();
        if (empty($columns)) {
            $columns = $db->getTableColumns($tableName);
        }

        $pkValue = $this->{$primaryKey} ?? null;
        $isInsert = empty($pkValue);

        if (!$isInsert) {
            $stmtExists = $db->prepare("SELECT COUNT(*) FROM `$tableName` WHERE `$primaryKey` = :pk_val");
            $stmtExists->execute([':pk_val' => $pkValue]);
            if ((int)$stmtExists->fetchColumn() === 0) {
                $isInsert = true;
            }
        }

        // Automatic timestamps management (created_at, updated_at)
        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if ($isInsert && in_array(static::CREATED_AT, $columns) && empty($this->attributes[static::CREATED_AT])) {
                $this->attributes[static::CREATED_AT] = $now;
            }
            if (in_array(static::UPDATED_AT, $columns)) {
                $this->attributes[static::UPDATED_AT] = $now;
            }
        }

        $saveData = [];
        foreach ($columns as $column) {
            if ($column === $primaryKey && empty($this->{$primaryKey})) {
                continue; // Skip primary key on insert
            }
            if (isset($this->attributes[$column])) {
                $saveData[$column] = $this->attributes[$column];
            }
        }

        // Input Guard: sanitize values before storage
        if ($this->inputGuarded) {
            $saveData = Guard::sanitizeAttributes($saveData, $this->rawFields);
        }

        if ($isInsert) {
            // INSERT record
            $fields = array_keys($saveData);
            $placeholders = array_map(fn($f) => ":$f", $fields);
            
            $sql = "INSERT INTO `$tableName` (" . 
                implode(',', array_map(fn($f) => "`$f`", $fields)) . 
                ") VALUES (" . 
                implode(',', $placeholders) . ")";

            $statement = $db->prepare($sql);
            foreach ($saveData as $key => $value) {
                $statement->bindValue(":$key", $value);
            }

            if ($statement->execute()) {
                $lastId = $db->pdo->lastInsertId();
                if (!empty($lastId) && $lastId != 0) {
                    $this->{$primaryKey} = $lastId;
                }
                return true;
            }
        } else {
            // UPDATE record
            $fields = array_keys($saveData);
            $setStatements = array_map(fn($f) => "`$f` = :$f", $fields);
            
            $sql = "UPDATE `$tableName` SET " . 
                implode(',', $setStatements) . 
                " WHERE `$primaryKey` = :pk_val";

            $statement = $db->prepare($sql);
            foreach ($saveData as $key => $value) {
                $statement->bindValue(":$key", $value);
            }
            $statement->bindValue(':pk_val', $pkValue);

            return $statement->execute();
        }

        return false;
    }

    /**
     * Active Record Find Single Record
     */
    public static function findOne(array $where): ?static
    {
        $tableName = static::tableName();
        $db = Application::$app->db;
        
        if (!$db) {
            return null;
        }

        $sql = "SELECT * FROM `$tableName`";
        if (!empty($where)) {
            $sql .= " WHERE ";
            $attributes = array_keys($where);
            $whereParts = array_map(fn($attr) => "`$attr` = :$attr", $attributes);
            $sql .= implode(' AND ', $whereParts);
        }
        $sql .= " LIMIT 1";

        $statement = $db->prepare($sql);
        foreach ($where as $key => $value) {
            $statement->bindValue(":$key", $value);
        }

        $statement->execute();
        $record = $statement->fetch();

        if (!$record) {
            return null;
        }

        $model = new static();
        $model->forceFill($record);
        return $model;
    }

    /**
     * Active Record Find Multiple Records
     */
    public static function find(array $where = [], array $options = []): array
    {
        $tableName = static::tableName();
        $db = Application::$app->db;

        if (!$db) {
            return [];
        }

        $sql = "SELECT * FROM `$tableName`";
        if (!empty($where)) {
            $sql .= " WHERE ";
            $whereParts = [];
            foreach (array_keys($where) as $attr) {
                $cleanAttr = preg_replace('/[^a-zA-Z0-9_.]/', '', $attr);
                if (str_contains($cleanAttr, '.')) {
                    [$tbl, $col] = explode('.', $cleanAttr, 2);
                    $whereParts[] = "`$tbl`.`$col` = :$cleanAttr";
                } else {
                    $whereParts[] = "`$cleanAttr` = :$cleanAttr";
                }
            }
            $sql .= implode(' AND ', $whereParts);
        }

        if (!empty($options['order_by'])) {
            $orderParts = explode(' ', trim((string)$options['order_by']));
            $orderCol = preg_replace('/[^a-zA-Z0-9_.]/', '', $orderParts[0]);
            $orderDir = (isset($orderParts[1]) && strtoupper(trim($orderParts[1])) === 'DESC') ? 'DESC' : 'ASC';
            if ($orderCol !== '') {
                if (str_contains($orderCol, '.')) {
                    [$tbl, $col] = explode('.', $orderCol, 2);
                    $sql .= " ORDER BY `$tbl`.`$col` $orderDir";
                } else {
                    $sql .= " ORDER BY `$orderCol` $orderDir";
                }
            }
        }

        if (isset($options['limit'])) {
            $sql .= " LIMIT " . (int)$options['limit'];
        }
        if (isset($options['offset'])) {
            $sql .= " OFFSET " . (int)$options['offset'];
        }

        $statement = $db->prepare($sql);
        foreach ($where as $key => $value) {
            $statement->bindValue(":$key", $value);
        }

        $statement->execute();
        $records = $statement->fetchAll();

        $models = [];
        foreach ($records as $record) {
            $model = new static();
            $model->forceFill($record);
            $models[] = $model;
        }

        return $models;
    }

    public static function all(): array
    {
        return static::find();
    }

    // =========================================================================
    // Find-or-Fail Methods
    // =========================================================================

    /**
     * Find a record by primary key or throw ModelNotFoundException.
     *
     * @throws ModelNotFoundException
     */
    public static function findOrFail(mixed $id): static
    {
        $model = new static();
        $pk = $model->primaryKey();
        $record = static::findOne([$pk => $id]);

        if (!$record) {
            throw new ModelNotFoundException(static::class, $id);
        }

        return $record;
    }

    /**
     * Find the first record matching conditions or throw ModelNotFoundException.
     *
     * @throws ModelNotFoundException
     */
    public static function firstOrFail(array $where): static
    {
        $record = static::findOne($where);

        if (!$record) {
            throw new ModelNotFoundException(static::class);
        }

        return $record;
    }

    // =========================================================================
    // Convenient Finders
    // =========================================================================

    /**
     * Find records where a single column matches a value.
     *
     *   User::findBy('email', 'john@example.com');
     */
    public static function findBy(string $column, mixed $value): array
    {
        return static::find([$column => $value]);
    }

    /**
     * Check if any record exists matching the given conditions.
     *
     *   if (User::exists(['email' => $email])) { ... }
     */
    public static function exists(array $where): bool
    {
        return static::count($where) > 0;
    }

    /**
     * Get the latest records, ordered by a column (default: primary key).
     *
     *   Post::latest();               // ORDER BY id DESC
     *   Post::latest('created_at');    // ORDER BY created_at DESC
     */
    public static function latest(string $column = null, int $limit = null): array
    {
        $model = new static();
        $col = $column ?? $model->primaryKey();
        $options = ['order_by' => "`$col` DESC"];
        if ($limit !== null) {
            $options['limit'] = $limit;
        }
        return static::find([], $options);
    }

    /**
     * Get the oldest records, ordered by a column ascending.
     *
     *   Post::oldest('created_at');
     */
    public static function oldest(string $column = null, int $limit = null): array
    {
        $model = new static();
        $col = $column ?? $model->primaryKey();
        $options = ['order_by' => "`$col` ASC"];
        if ($limit !== null) {
            $options['limit'] = $limit;
        }
        return static::find([], $options);
    }

    /**
     * Pluck a single column's values from matching records.
     *
     *   $emails = User::pluck('email');                      // ['a@b.com', 'c@d.com']
     *   $map    = User::pluck('email', 'id');                // [1 => 'a@b.com', 2 => 'c@d.com']
     */
    public static function pluck(string $column, string $keyColumn = null, array $where = []): array
    {
        $records = static::find($where);
        $result = [];

        foreach ($records as $record) {
            if ($keyColumn !== null) {
                $result[$record->{$keyColumn}] = $record->{$column};
            } else {
                $result[] = $record->{$column};
            }
        }

        return $result;
    }

    // =========================================================================
    // Static Factory Methods
    // =========================================================================

    /**
     * Create a new record and persist it immediately.
     *
     *   $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
     */
    public static function create(array $data): static
    {
        $model = new static();
        $model->loadData($data);
        $model->save();
        return $model;
    }

    /**
     * Find the first record matching attributes, or create it.
     *
     *   $user = User::firstOrCreate(
     *       ['email' => 'john@example.com'],       // search by
     *       ['name' => 'John Doe']                  // extra data for creation
     *   );
     */
    public static function firstOrCreate(array $where, array $extra = []): static
    {
        $existing = static::findOne($where);

        if ($existing) {
            return $existing;
        }

        return static::create(array_merge($where, $extra));
    }

    /**
     * Find and update or create if not found.
     *
     *   $user = User::updateOrCreate(
     *       ['email' => 'john@example.com'],       // search by
     *       ['name' => 'John Doe', 'role' => 'admin']  // data to update/set
     *   );
     */
    public static function updateOrCreate(array $where, array $data): static
    {
        $existing = static::findOne($where);

        if ($existing) {
            $existing->fill($data);
            $existing->save();
            return $existing;
        }

        return static::create(array_merge($where, $data));
    }

    /**
     * Delete record(s) by primary key.
     *
     *   User::destroy(1);
     *   User::destroy(1, 2, 3);
     *   User::destroy([1, 2, 3]);
     */
    public static function destroy(mixed ...$ids): int
    {
        $ids = is_array($ids[0] ?? null) ? $ids[0] : $ids;
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $model = static::findOrFail($id);
                if ($model->delete()) {
                    $deleted++;
                }
            } catch (ModelNotFoundException) {
                // Skip missing records
            }
        }

        return $deleted;
    }

    // =========================================================================
    // Instance Helpers
    // =========================================================================

    /**
     * Mass-assign attributes (without saving).
     *
     *   $user->fill(['name' => 'Jane', 'email' => 'jane@example.com']);
     */
    public function fill(array $data): static
    {
        $data = Guard::filterMassAssignment($data, $this->fillable, $this->guarded);

        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
        return $this;
    }

    /**
     * Mass-assign attributes and save immediately.
     *
     *   $user->update(['name' => 'Updated Name']);
     */
    public function update(array $data): bool
    {
        $this->fill($data);
        return $this->save();
    }

    /**
     * Reload the model's attributes from the database.
     *
     *   $user->fresh();  // Re-fetches from DB
     */
    public function fresh(): ?static
    {
        $pk = $this->primaryKey();
        $pkValue = $this->{$pk} ?? null;

        if (empty($pkValue)) {
            return null;
        }

        return static::findOne([$pk => $pkValue]);
    }

    /**
     * Reload this instance in-place from the database.
     *
     *   $user->refresh();  // Same instance, updated attributes
     */
    public function refresh(): static
    {
        $fresh = $this->fresh();

        if ($fresh) {
            $this->attributes = $fresh->attributes;
        }

        return $this;
    }

    /**
     * Convert the model's attributes to a plain array.
     *
     *   $array = $user->toArray();
     */
    public function toArray(): array
    {
        $attributes = $this->attributes;

        if (!empty($this->visible)) {
            $attributes = array_intersect_key($attributes, array_flip($this->visible));
        }

        if (!empty($this->hidden)) {
            foreach ($this->hidden as $key) {
                unset($attributes[$key]);
            }
        }

        return $attributes;
    }

    /**
     * Temporarily hide attributes from array/JSON serialization.
     */
    public function makeHidden(array|string $attributes): static
    {
        $this->hidden = array_merge($this->hidden, (array)$attributes);
        return $this;
    }

    /**
     * Temporarily make hidden attributes visible in array/JSON serialization.
     */
    public function makeVisible(array|string $attributes): static
    {
        $this->hidden = array_diff($this->hidden, (array)$attributes);
        return $this;
    }

    /**
     * Convert the model's attributes to a JSON string.
     *
     *   echo $user->toJson();
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Check if the model has been persisted (has a primary key value).
     *
     *   if ($user->isPersisted()) { ... }
     */
    public function isPersisted(): bool
    {
        $pk = $this->primaryKey();
        return !empty($this->{$pk});
    }

    /**
     * Duplicate the model instance (without the primary key).
     *
     *   $clone = $user->replicate();
     *   $clone->email = 'new@email.com';
     *   $clone->save();
     */
    public function replicate(): static
    {
        $clone = new static();
        $pk = $this->primaryKey();

        foreach ($this->attributes as $key => $value) {
            if ($key !== $pk) {
                $clone->{$key} = $value;
            }
        }

        return $clone;
    }

    /**
     * Get matching records count
     */
    public static function count(array $where = []): int
    {
        $tableName = static::tableName();
        $db = Application::$app->db;

        if (!$db) {
            return 0;
        }

        $sql = "SELECT COUNT(*) FROM `$tableName`";
        if (!empty($where)) {
            $sql .= " WHERE ";
            $attributes = array_keys($where);
            $whereParts = array_map(fn($attr) => "`$attr` = :$attr", $attributes);
            $sql .= implode(' AND ', $whereParts);
        }

        $statement = $db->prepare($sql);
        foreach ($where as $key => $value) {
            $statement->bindValue(":$key", $value);
        }

        $statement->execute();
        return (int)$statement->fetchColumn();
    }

    /**
     * Paginate queries, returning a responsive Paginator instance (compatible with array access)
     */
    public static function paginate(int $perPage = 15, ?int $page = null, array $where = [], array $options = []): Paginator
    {
        $pageName = $options['pageName'] ?? $options['page_name'] ?? 'page';
        if ($page === null) {
            $req = Application::$app->request ?? null;
            $rawPage = $req ? $req->get($pageName, 1) : ($_GET[$pageName] ?? 1);
            $page = is_numeric($rawPage) ? (int)$rawPage : 1;
        }

        $total = static::count($where);
        $lastPage = (int)ceil($total / $perPage);
        if ($lastPage < 1) {
            $lastPage = 1;
        }
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        $options['limit'] = $perPage;
        $options['offset'] = $offset;

        $data = static::find($where, $options);

        return new Paginator($data, $total, $perPage, $page, array_merge($options, [
            'pageName' => $pageName,
        ]));
    }

    /**
     * Chunk queries for large dataset processing
     */
    public static function chunk(int $size, callable $callback, array $where = [], array $options = []): void
    {
        $page = 1;
        do {
            $offset = ($page - 1) * $size;
            $options['limit'] = $size;
            $options['offset'] = $offset;
            
            $records = static::find($where, $options);
            
            if (empty($records)) {
                break;
            }

            if ($callback($records, $page) === false) {
                break;
            }

            $page++;
        } while (count($records) === $size);
    }

    /**
     * Active Record Delete Record (supports soft deletes if $softDelete is enabled)
     */
    public function delete(): bool
    {
        $primaryKey = $this->primaryKey();
        $pkValue = $this->{$primaryKey} ?? null;

        if (empty($pkValue)) {
            return false;
        }

        // Soft delete if enabled
        if ($this->softDelete) {
            $now = date('Y-m-d H:i:s');
            $this->{static::DELETED_AT} = $now;
            return $this->update([static::DELETED_AT => $now]);
        }

        return $this->forceDelete();
    }

    /**
     * Permanently delete a record from the database.
     */
    public function forceDelete(): bool
    {
        $tableName = static::tableName();
        $primaryKey = $this->primaryKey();
        $pkValue = $this->{$primaryKey} ?? null;

        if (empty($pkValue)) {
            return false;
        }

        $db = Application::$app->db;
        if (!$db) {
            return false;
        }

        $statement = $db->prepare("DELETE FROM `$tableName` WHERE `$primaryKey` = :pk");
        $statement->bindValue(':pk', $pkValue);
        return $statement->execute();
    }

    /**
     * Restore a soft-deleted record.
     */
    public function restore(): bool
    {
        if (!$this->softDelete) {
            return false;
        }

        $this->{static::DELETED_AT} = null;
        return $this->update([static::DELETED_AT => null]);
    }

    /**
     * Check if this model instance has been soft deleted.
     */
    public function isTrashed(): bool
    {
        return !empty($this->{static::DELETED_AT});
    }

    // =========================================================================
    // Model Relationships
    // =========================================================================

    /**
     * Define a one-to-one relationship.
     *
     * Example:
     *   public function profile(): HasOne {
     *       return $this->hasOne(Profile::class);
     *   }
     */
    public function hasOne(string $relatedClass, ?string $foreignKey = null, ?string $localKey = null): \Ace\Relations\HasOne
    {
        return new \Ace\Relations\HasOne($this, $relatedClass, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-many relationship.
     *
     * Example:
     *   public function posts(): HasMany {
     *       return $this->hasMany(Post::class);
     *   }
     */
    public function hasMany(string $relatedClass, ?string $foreignKey = null, ?string $localKey = null): \Ace\Relations\HasMany
    {
        return new \Ace\Relations\HasMany($this, $relatedClass, $foreignKey, $localKey);
    }

    /**
     * Define an inverse one-to-one or one-to-many relationship.
     *
     * Example:
     *   public function user(): BelongsTo {
     *       return $this->belongsTo(User::class);
     *   }
     */
    public function belongsTo(string $relatedClass, ?string $foreignKey = null, ?string $ownerKey = null): \Ace\Relations\BelongsTo
    {
        return new \Ace\Relations\BelongsTo($this, $relatedClass, $foreignKey, $ownerKey);
    }

    /**
     * Define a many-to-many relationship via a pivot table.
     *
     * Example:
     *   public function roles(): BelongsToMany {
     *       return $this->belongsToMany(Role::class, 'role_user');
     *   }
     */
    public function belongsToMany(
        string $relatedClass,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): \Ace\Relations\BelongsToMany {
        return new \Ace\Relations\BelongsToMany(
            $this,
            $relatedClass,
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey
        );
    }
}


