<?php
/**
 * The validation rule factory.
 *
 * @package    Framework
 * @subpackage Validation
 * @since      1.0.0
 */

namespace Framework\Validation;

use Closure;
use Framework\Exceptions\InvalidValidationRuleException;
use Framework\Supports\Arr;
use ReflectionMethod;

defined('ABSPATH') || exit;

use function Framework\message;
use function Framework\Polyfill\array_first;
use function Framework\Polyfill\array_last;
use function Framework\Polyfill\str_contains;
use function Framework\Polyfill\str_starts_with;
use function Framework\throw_if;

class RuleFactory
{
    /**
     * The raw rules to build instances from.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $rules = [];

    /**
     * The base rules mapped to their constraint definitions.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $chain = [];

    /**
     * The cache of resolved rule instances keyed by rule name.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static array $rule_class_cache = [];

    /**
     * Store the arguments of the base rules.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $base_rule_arguments = [];

    /**
     * Construct the factory.
     *
     * @param array $rules The rules to construct the factory with.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
        $this->build_rules_chain($rules);
    }

    /**
     * Make the rule instances from the given rule definitions.
     *
     * @param array $rules The rule definitions.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function make(array $rules)
    {
        return (new static($rules))->make_rule_array();
    }

    /**
     * Build the rule instances from the chain.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function make_rule_array()
    {
        $rules = [];

        foreach ($this->chain as $rule_name => $constraints) {
            $rules[] = $this->build_rule($rule_name, $constraints);
        }

        return $this->finalize($rules);
    }

    /**
     * Finalize the rules.
     * If the rules have an implicit rule then remove the nullable rule.
     *
     * @param array $rules The rules to filter.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function finalize($rules)
    {
        usort(
            $rules,
            fn ($first) => $first instanceof ValidationRule && 
                ($first->is_implicit || in_array($first->get_rule_name(), ['nullable', 'sometimes'], true))
                    ? -1
                    : 1
        );

        return $rules;
    }

    /**
     * Build a rule instance and apply its constraints.
     *
     * @param string $rule The rule to build.
     * @param array $constraints The constraints from the chain.
     *
     * @return ValidationRule
     *
     * @since 1.0.0
     */
    protected function build_rule(string $rule, array $constraints)
    {
        if (is_subclass_of($rule, ValidationRule::class)) {
            return array_first($constraints);
        }

        if (str_starts_with($rule, 'closure-')) {
            return array_first($constraints);
        }

        $rule_name = $this->rule_name($rule);
        $rule_arguments = $this->rule_arguments($rule) ?? $this->base_rule_arguments[$rule_name] ?? null;

        $instance = Rule::$rule_name($rule_arguments);

        foreach ($constraints as $constraint) {
            $name = $this->rule_name($constraint);
            $arguments = $this->rule_arguments($constraint);

            if (!$instance->has_constraint($name)) {
                continue;
            }

            if ($arguments !== null) {
                $instance->$name($arguments);
            } else {
                $instance->$name();
            }
        }

        return $instance;
    }

    /**
     * Build the chain of base rules and their constraints.
     *
     * @param array $rules The rules to build the chain with.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function build_rules_chain(array $rules)
    {
        $last_base_rule = null;

        foreach ($rules as $rule) {
            if ($rule instanceof ValidationRule) {
                $this->chain[get_class($rule)] = [$rule];
                continue;
            }

            if ($rule instanceof Closure) {
                $this->chain['closure-' . uniqid()] = [$rule];
                continue;
            }

            $rule_name = $this->rule_name($rule);
            $arguments = $this->rule_arguments($rule);

            $rule_class = $this->get_rule_class($rule_name, $arguments);

            throw_if(
                $rule_class === null && $last_base_rule === null,
                message('validator.invalid_rule', [$rule]),
                InvalidValidationRuleException::class
            );

            if ($rule_class !== null) {
                $last_base_rule = $rule_class->get_rule_name();
            }

            $this->group_under_base_rule($rule, $last_base_rule, $arguments);
        }
    }

    /**
     * Group a rule under its base rule when it is a supported constraint.
     *
     * @param string $rule The rule to group.
     * @param string|null $last_base_rule The last base rule.
     * @param mixed $arguments The arguments of the rule.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function group_under_base_rule($rule, $last_base_rule, $arguments)
    {
        if ($last_base_rule === null) {
            return;
        }

        $rule_name = $this->rule_name($rule);
        $this->chain[$last_base_rule] ??= [];

        if (!isset($this->base_rule_arguments[$last_base_rule])) {
            $this->base_rule_arguments[$last_base_rule] = $arguments;
        }

        if (Rule::$last_base_rule($arguments)->has_constraint($rule_name)) {
            $this->chain[$last_base_rule][] = $rule;
        }
    }

    /**
     * Resolve a rule instance by its name when a factory method exists.
     *
     * @param string $rule The rule to resolve.
     * @param mixed $arguments The arguments of the rule.
     *
     * @return ValidationRule|null
     *
     * @since 1.0.0
     */
    protected function get_rule_class(string $rule, $arguments = null)
    {
        if (isset(static::$rule_class_cache[$rule])) {
            return static::$rule_class_cache[$rule];
        }

        if (!method_exists(Rule::class, $rule)) {
            return null;
        }

        $method = new ReflectionMethod(Rule::class, $rule);

        return static::$rule_class_cache[$rule] = is_null($arguments)
            ? $method->invoke(null)
            : $method->invokeArgs(null, Arr::wrap($arguments));
    }

    /**
     * Get the name of the rule.
     *
     * @param string $rule The rule to get the name of.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function rule_name(string $rule)
    {
        return array_first(explode(':', $rule, 2));
    }

    /**
     * Get the arguments of the rule.
     *
     * @param string $rule The rule to get the arguments of.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function rule_arguments(string $rule)
    {
        if (!str_contains($rule, ':')) {
            return null;
        }

        $arguments = array_last(explode(':', $rule, 2));

        if ($arguments === null || $arguments === '') {
            return null;
        }

        if (str_contains($arguments, ',')) {
            return explode(',', $arguments);
        }

        return $arguments;
    }
}
