<?php

namespace Modules\AI\Support;

use RuntimeException;

/**
 * Loads the module-owned FAtiMA scenario.
 *
 * The scenario is authored content, not generated state: every appraisal rule and
 * every social exchange lives in the committed fixture, so changing what the driver
 * considers threatening is an authored change rather than a code change. The
 * sidecar is stateless between requests because the module re-sends this template,
 * which is also what makes each appraisal start from a clean emotional state.
 */
class FatimaScenarioTemplate
{
    private string|null $scenario = null;

    private string|null $assets = null;

    public function scenarioJson(): string
    {
        return $this->scenario ??= $this->read('ogame-cognition.json');
    }

    public function assetsJson(): string
    {
        return $this->assets ??= $this->read('ogame-cognition-assets.json');
    }

    private function read(string $file): string
    {
        $directory = rtrim((string) config(
            'ai.cognition.fatima.scenario_path',
            dirname(__DIR__, 2) . '/docker/cognition/fatima/scenarios',
        ), '/');
        $path = $directory . '/' . $file;
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('The FAtiMA scenario fixture is missing: %s', $path));
        }

        return $contents;
    }
}
