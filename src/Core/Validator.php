<?php
/**
 * Validator — small fluent validator for request input.
 *
 *   $v = Validator::make($request->all(), [
 *       'email'    => ['required', 'email'],
 *       'password' => ['required', 'min:8', 'max:72'],
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 */

declare(strict_types=1);

namespace CodeAlpha\Core;

final class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];

    private function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function fails(): bool
    {
        $this->errors = [];
        foreach ($this->rules as $field => $rules) {
            $rules = is_array($rules) ? $rules : explode('|', (string) $rules);
            $value = $this->data[$field] ?? null;
            $present = $value !== null && $value !== '';
            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', (string) $rule, 2), 2, null);
                $failed = match ($name) {
                    'required' => !$present,
                    'email'    => $present && !filter_var($value, FILTER_VALIDATE_EMAIL),
                    'min'      => $present && mb_strlen((string) $value) < (int) $param,
                    'max'      => $present && mb_strlen((string) $value) > (int) $param,
                    'int'      => $present && !filter_var($value, FILTER_VALIDATE_INT),
                    'numeric'  => $present && !is_numeric($value),
                    'array'    => $present && !is_array($value),
                    'string'   => $present && !is_string($value),
                    'in'       => $present && !in_array($value, explode(',', (string) $param), true),
                    'date'     => $present && strtotime((string) $value) === false,
                    'confirmed' => $present && ($this->data[$field . '_confirmation'] ?? null) !== $value,
                    'url'      => $present && !filter_var($value, FILTER_VALIDATE_URL),
                    'phone'    => $present && !preg_match('/^\+?[0-9]{7,15}$/', (string) $value),
                    default    => false,
                };
                if ($failed) {
                    $this->errors[$field][] = $this->message($field, $name, $param);
                }
            }
        }
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            return $messages[0];
        }
        return 'Validation failed.';
    }

    private function message(string $field, string $rule, ?string $param): string
    {
        $label = str_replace('_', ' ', $field);
        return match ($rule) {
            'required'  => ucfirst($label) . ' is required.',
            'email'     => ucfirst($label) . ' must be a valid email address.',
            'min'       => ucfirst($label) . ' must be at least ' . $param . ' characters.',
            'max'       => ucfirst($label) . ' must be at most ' . $param . ' characters.',
            'int'       => ucfirst($label) . ' must be an integer.',
            'numeric'   => ucfirst($label) . ' must be a number.',
            'array'     => ucfirst($label) . ' must be a list.',
            'string'    => ucfirst($label) . ' must be text.',
            'in'        => ucfirst($label) . ' has an invalid value.',
            'date'      => ucfirst($label) . ' must be a valid date.',
            'confirmed' => ucfirst($label) . ' confirmation does not match.',
            'url'       => ucfirst($label) . ' must be a valid URL.',
            'phone'     => ucfirst($label) . ' must be a valid phone number.',
            default     => ucfirst($label) . ' is invalid.',
        };
    }

    /** Return only the fields covered by the rules (mass-assignment guard). */
    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $out[$field] = $this->data[$field];
            }
        }
        return $out;
    }
}
