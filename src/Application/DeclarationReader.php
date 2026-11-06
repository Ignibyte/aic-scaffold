<?php

declare(strict_types=1);

namespace AIC\Scaffold\Application;

use AIC\Scaffold\Exception\ScaffoldRefusal;
use stdClass;

use function array_is_list;
use function array_keys;
use function array_map;
use function get_object_vars;
use function in_array;
use function is_array;
use function is_string;
use function strval;

/**
 * Typed reads over one `extra.aic-scaffold` object, decoded from a package's
 * composer.json: each value is narrowed where it is read, and a wrong type or
 * an unknown key is refused by name.
 */
final readonly class DeclarationReader
{
    /**
     * @param stdClass $object The object.
     * @param string   $what   What it is, for refusals: `ignibyte/aic-framework's extra.aic-scaffold`.
     */
    public function __construct(
        private stdClass $object,
        private string $what,
    ) {
    }

    /**
     * @param list<string> $known The keys it may hold.
     *
     * @throws ScaffoldRefusal When it holds another.
     */
    public function only(array $known): void
    {
        foreach ($this->keys() as $key) {
            if (!in_array($key, $known, true)) {
                throw new ScaffoldRefusal($this->what . ' has an unknown key "' . $key . '".');
            }
        }
    }

    /**
     * @return list<string> Its keys, in order.
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys(get_object_vars($this->object)));
    }

    /**
     * @param string $key A key.
     *
     * @throws ScaffoldRefusal When it holds something other than an object there.
     *
     * @return self A reader over the object there; an empty one when the key is missing.
     */
    public function object(string $key): self
    {
        $value = get_object_vars($this->object)[$key] ?? [];

        // Composer decodes `{}` as an empty array, so an empty or missing object arrives as `[]`.
        if ($value === []) {
            return new self(new stdClass(), $this->what . "'s \"" . $key . '"');
        }

        if (!$value instanceof stdClass) {
            throw new ScaffoldRefusal($this->what . '\'s "' . $key . '" must be an object.');
        }

        return new self($value, $this->what . '\'s "' . $key . '"');
    }

    /**
     * @throws ScaffoldRefusal When a member is not an object.
     *
     * @return array<string, self> A reader over each member, by key.
     */
    public function members(): array
    {
        $members = [];

        foreach ($this->keys() as $key) {
            $members[$key] = $this->object($key);
        }

        return $members;
    }

    /**
     * @param string $key A key.
     *
     * @throws ScaffoldRefusal When it is missing or not a string.
     *
     * @return string Its value.
     */
    public function string(string $key): string
    {
        $value = get_object_vars($this->object)[$key] ?? null;

        if (!is_string($value)) {
            throw new ScaffoldRefusal($this->what . '\'s "' . $key . '" must be a string.');
        }

        return $value;
    }

    /**
     * @param string $key A key.
     *
     * @throws ScaffoldRefusal When it is not a list of strings.
     *
     * @return list<string> Its value; empty when the key is missing.
     */
    public function strings(string $key): array
    {
        $value = get_object_vars($this->object)[$key] ?? [];

        if (!is_array($value) || !array_is_list($value)) {
            throw $this->notStrings($key);
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw $this->notStrings($key);
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param string $expected What a member must be, for a refusal: `a path or false`, `false`.
     *
     * @throws ScaffoldRefusal When a member is neither a string nor false.
     *
     * @return array<string, string|false> Each member, by key: a string, or false for an opt-out.
     */
    public function stringsOrFalse(string $expected): array
    {
        $values = [];

        foreach ($this->keys() as $key) {
            $value = get_object_vars($this->object)[$key] ?? null;

            if ($value !== false && !is_string($value)) {
                throw new ScaffoldRefusal($this->what . '\'s "' . $key . '" must be ' . $expected . '.');
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param string $key A key.
     *
     * @return ScaffoldRefusal That its value is not a list of strings.
     */
    private function notStrings(string $key): ScaffoldRefusal
    {
        return new ScaffoldRefusal($this->what . '\'s "' . $key . '" must be a list of strings.');
    }
}
