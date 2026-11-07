<?php

namespace Modules\AI\Domain\Advisor;

/**
 * One question to the advisor: the legal options the rules computed and the rule's own choice.
 *
 * Everything here is what this account can see: its own state and feelings, its own reports with
 * their age, the public highscore, its messages and its alliance's shared record. The caller builds
 * it from AdvisorFacts, never from another account's hidden state. `speech` is set when the chosen
 * option is spoken: the authored line (the fallback), the facts the line must keep, and the
 * recipient's language.
 */
readonly class AdvisorQuestion
{
    /**
     * @param list<array{id: string, summary: string, forecast?: array<string, scalar|null>}> $options
     * @param array<string, mixed> $situation
     * @param list<array{kind: string, age_hours?: int|null, value: mixed}> $evidence
     * @param array{authored: string, facts?: array<array-key, scalar|null>, language?: string, persona?: string}|null $speech
     */
    public function __construct(
        public AdvisorArea $area,
        public int $playerId,
        public string $moment,
        public array $options,
        public string $ruleChoice,
        public array $situation = [],
        public array $evidence = [],
        public array|null $speech = null,
    ) {
    }

    /** @return list<string> */
    public function optionIds(): array
    {
        return array_values(array_map(static fn (array $option): string => $option['id'], $this->options));
    }

    /**
     * The serialized brief the provider reads. Deterministic: the same visible facts give the same
     * bytes, which is what the no-cheating tests compare.
     */
    public function brief(): string
    {
        return json_encode([
            'area' => $this->area->value,
            'moment' => $this->moment,
            'situation' => $this->situation,
            'options' => $this->options,
            'rule_choice' => $this->ruleChoice,
            'evidence' => array_map(
                static fn (array $item, int $index): array => ['id' => $index + 1] + $item,
                $this->evidence,
                array_keys($this->evidence),
            ),
            'speech' => $this->speech,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
