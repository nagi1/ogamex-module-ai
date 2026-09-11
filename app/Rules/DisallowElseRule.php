<?php

namespace Modules\AI\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Else_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Else_> */
final class DisallowElseRule implements Rule
{
    public function getNodeType(): string
    {
        return Else_::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message('Use an early return, match expression, strategy, or lookup table instead of else.')
                ->identifier('ai.elseForbidden')
                ->build(),
        ];
    }
}
