<?php

namespace Modules\AI\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<New_> */
final class DisallowDirectInstantiationRule implements Rule
{
    public function getNodeType(): string
    {
        return New_::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        // The contract forbids `new` for the module's own actions, services,
        // policies, drivers and domain payloads, because those are resolved
        // through the container so they stay replaceable. Host objects, Laravel
        // framework classes and native exceptions are not module-managed, so
        // instantiating them directly is not this rule's concern.
        $class = $node->class;
        if ($class instanceof Name && !str_starts_with($scope->resolveName($class), 'Modules\\AI\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Resolve module-managed objects through app() or app()->makeWith().')
                ->identifier('ai.directInstantiation')
                ->build(),
        ];
    }
}
