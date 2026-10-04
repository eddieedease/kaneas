<?php

declare(strict_types=1);

namespace Kaneas\Core;

/**
 * Tiny rule based validator.
 *
 *   Validator::validate($req->all(), [
 *       'email' => 'required|email|max:190',
 *       'role'  => 'in:editor,viewer',
 *   ]);
 *
 * Rules: required, string, raw (don't trim), email, int, bool, min:n, max:n, bytes:n, in:a,b
 * Returns only the validated keys. Throws a 422 with { field: rule } on failure.
 */
final class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $present = array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';
            $value = $data[$field] ?? null;

            if (!$present) {
                if (in_array('required', $ruleList, true)) {
                    $errors[$field] = 'required';
                }
                continue;
            }

            if (is_string($value) && !in_array('raw', $ruleList, true)) {
                $value = trim($value);
                if ($value === '' && in_array('required', $ruleList, true)) {
                    $errors[$field] = 'required';
                    continue;
                }
            }

            $error = self::check($value, $ruleList);
            if ($error !== null) {
                $errors[$field] = $error;
                continue;
            }

            $clean[$field] = match (true) {
                in_array('int', $ruleList, true) => (int) $value,
                in_array('bool', $ruleList, true) => (bool) $value,
                default => $value,
            };
        }

        if ($errors) {
            throw HttpException::validation($errors);
        }
        return $clean;
    }

    private static function check(mixed $value, array $rules): ?string
    {
        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $ok = match ($name) {
                'required', 'raw' => true,
                'string' => is_string($value),
                'email' => is_string($value) && strlen($value) <= 190 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                'int' => is_int($value) || (is_string($value) && ctype_digit($value)),
                'bool' => is_bool($value) || $value === 0 || $value === 1,
                'min' => is_string($value) ? mb_strlen($value) >= (int) $arg : (is_numeric($value) && $value >= (int) $arg),
                'max' => is_string($value) ? mb_strlen($value) <= (int) $arg : (is_numeric($value) && $value <= (int) $arg),
                'bytes' => is_string($value) && strlen($value) <= (int) $arg,
                'in' => in_array((string) $value, explode(',', (string) $arg), true),
                default => throw new \LogicException("Unknown validation rule: $name"),
            };
            if (!$ok) {
                return $arg === null ? $name : "$name:$arg";
            }
        }
        return null;
    }
}
