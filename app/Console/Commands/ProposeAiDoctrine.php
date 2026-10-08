<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\ProposeAiDoctrineFromSourceAction;
use Symfony\Component\Yaml\Yaml;

#[Description('Propose doctrine entries from OGame wiki pages, checked against their sources, for the owner to merge.')]
#[Signature('ai:doctrine-proposals
        {paths* : Wiki source files (plan/research/ogame/raw/wiki/...)}
        {--out=plan/research/ogame/doctrine-proposals.yaml : Where the proposals are written}')]
class ProposeAiDoctrine extends Command
{
    public function handle(): int
    {
        // The owner asked for this run: the classifier is used whatever the play-time setting says.
        config(['ai.classification.enabled' => true]);
        $proposals = [];

        foreach ((array) $this->argument('paths') as $path) {
            $page = is_file($path) ? (string) file_get_contents($path) : '';
            $found = $page === '' ? [] : app(ProposeAiDoctrineFromSourceAction::class)->handle($page);
            $this->line(sprintf('%s: %d proposal(s)', basename($path), count($found)));
            $proposals = [...$proposals, ...array_map(static fn (array $proposal): array => ['source' => $path, ...$proposal], $found)];
        }

        file_put_contents((string) $this->option('out'), Yaml::dump(['proposals' => $proposals], 6, 2));
        $this->info(count($proposals) . ' proposal(s) written to ' . $this->option('out'));

        return self::SUCCESS;
    }
}
