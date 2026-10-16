<?php

namespace MedCore\Core;

/**
 * Server-side input validation.
 *
 * $v = Validator::make($input, [
 *     'email' => 'required|email|max:190',
 *     'name'  => 'required|min:2|max:80',
 * ]);
 * if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    private array $errors = [];

    private function __construct(private array $data, private array $rules)
    {
        $this->validate();
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function first(string $key): ?string
    {
        return $this->errors[$key][0] ?? null;
    }

    /** Validated subset of input (only keys with rules). */
    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $key) {
            if (array_key_exists($key, $this->data)) {
                $out[$key] = is_string($this->data[$key]) ? trim($this->data[$key]) : $this->data[$key];
            }
        }
        return $out;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $isEmpty = $value === null || $value === '';

            foreach ($rules as $rule) {
                if ($rule instanceof \Closure) {
                    $message = $rule($value, $this->data);
                    if (is_string($message)) {
                        $this->addError($field, $message);
                    }
                    continue;
                }

                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramString] = explode(':', $rule, 2);
                    $params = explode(',', $paramString);
                }

                if ($isEmpty && $rule !== 'required') {
                    continue; // other rules skip empty optional fields
                }

                switch ($rule) {
                    case 'required':
                        if ($isEmpty) {
                            $this->addError($field, $this->label($field) . ' is required.');
                        }
                        break;
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $this->addError($field, 'Enter a valid email address.');
                        }
                        break;
                    case 'min':
                        if (mb_strlen((string) $value) < (int) $params[0]) {
                            $this->addError($field, $this->label($field) . " must be at least {$params[0]} characters.");
                        }
                        break;
                    case 'max':
                        if (mb_strlen((string) $value) > (int) $params[0]) {
                            $this->addError($field, $this->label($field) . " must not exceed {$params[0]} characters.");
                        }
                        break;
                    case 'same':
                        if ($value !== ($this->data[$params[0]] ?? null)) {
                            $this->addError($field, $this->label($field) . ' does not match.');
                        }
                        break;
                    case 'in':
                        if (!in_array((string) $value, $params, true)) {
                            $this->addError($field, $this->label($field) . ' is invalid.');
                        }
                        break;
                    case 'numeric':
                        if (!is_numeric($value)) {
                            $this->addError($field, $this->label($field) . ' must be a number.');
                        }
                        break;
                    case 'integer':
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $this->addError($field, $this->label($field) . ' must be a whole number.');
                        }
                        break;
                    case 'date':
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) || !strtotime((string) $value)) {
                            $this->addError($field, $this->label($field) . ' must be a valid date (YYYY-MM-DD).');
                        }
                        break;
                    case 'datetime':
                        $ts = strtotime((string) $value);
                        if ($ts === false) {
                            $this->addError($field, $this->label($field) . ' must be a valid date and time.');
                        }
                        break;
                    case 'phone':
                        if (!preg_match('/^[0-9+\-\s()]{7,20}$/', (string) $value)) {
                            $this->addError($field, $this->label($field) . ' must be a valid phone number.');
                        }
                        break;
                    case 'alpha_dash':
                        if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string) $value)) {
                            $this->addError($field, $this->label($field) . ' may only contain letters, numbers, dashes and underscores.');
                        }
                        break;
                }
            }
        }
    }
}
