<?php

namespace Modules\AI\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
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
        return [
            RuleErrorBuilder::message('Resolve module-managed objects through app() or app()->makeWith().')
                ->identifier('ai.directInstantiation')
                ->build(),
        ];
    }
}
