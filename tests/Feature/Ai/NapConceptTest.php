<?php

/*
 * The extraction behind this ticket found no NAP mechanics, so the AI module deliberately carries
 * no NAP handling: there is no policy to check and no decision to make. These checks record that
 * gap instead of guessing one. They stay green while nothing NAP-named is resolvable in the
 * module and go red the moment NAP behaviour lands here. The concept is UNCONFIRMED: a red run
 * means the mechanics have to be confirmed first, it is not evidence that NAP is decided.
 */

use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiNapGapModuleTestCase extends IsolatedAccountTestCase
{
    private ?string $statusesFile = null;

    public function createApplication(): Application
    {
        $statuses = $this->trackedModuleStatuses();

        if ($statuses !== null) {
            $statuses['AI'] = true;
            $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
            file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
            putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);
        }

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if ($this->statusesFile !== null && is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }

    protected function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> */
    protected function enumCaseNames(): array
    {
        $names = [];

        foreach ($this->phpFilesUnder($this->moduleRoot() . '/app/Enums') as $file) {
            $enum = $this->classForModuleFile($file);

            if (! enum_exists($enum)) {
                continue;
            }

            foreach ($enum::cases() as $case) {
                $names[] = $enum . '::' . $case->name;
            }
        }

        return $names;
    }

    /** @return list<string> */
    protected function resolvableNapActions(): array
    {
        $offenders = [];

        foreach ($this->phpFilesUnder($this->moduleRoot() . '/app/Actions') as $file) {
            $class = $this->classForModuleFile($file);

            // Only a class the container can actually build counts: a file that declares no
            // class is not something an account can run.
            if (! $this->namesNap($class) || ! class_exists($class)) {
                continue;
            }

            app()->make($class);

            $offenders[] = $class;
        }

        return $offenders;
    }

    /** @return list<string> */
    protected function configKeys(): array
    {
        $keys = [];

        foreach ($this->phpFilesUnder($this->moduleRoot() . '/config') as $file) {
            $config = require $file;

            if (! is_array($config)) {
                continue;
            }

            $prefix = basename($file, '.php');

            foreach (array_keys(Arr::dot($config)) as $key) {
                $keys[] = $prefix . '.' . $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    protected function napNamed(array $names): array
    {
        return array_values(array_filter($names, fn (string $name): bool => $this->namesNap($name)));
    }

    // A word match, not a substring one: "Snapshot" is not a NAP, "NapPolicy" is.
    protected function namesNap(string $name): bool
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name);
        $words = preg_split('/[^A-Za-z0-9]+/', strtolower((string) $spaced));

        return in_array('nap', $words ?: [], true);
    }

    /** @return list<string> */
    protected function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    protected function classForModuleFile(string $file): string
    {
        $relative = str_replace($this->moduleRoot() . DIRECTORY_SEPARATOR, '', $file);
        $path = str_replace('.php', '', $relative);

        return 'Modules\\AI\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $path);
    }

    private function trackedModuleStatuses(): ?array
    {
        $trackedFile = dirname(__DIR__, 5) . '/modules_statuses.json';

        if (! is_file($trackedFile)) {
            return null;
        }

        $statuses = json_decode((string) file_get_contents($trackedFile), true);

        return is_array($statuses) ? $statuses : null;
    }
}

uses(AiNapGapModuleTestCase::class);

it('declares no NAP enum case, because NAP mechanics are unconfirmed', function () {
    expect(is_dir($this->moduleRoot()))->toBeTrue();
    expect($this->napNamed($this->enumCaseNames()))->toBe([]);
});

it('resolves no NAP action class, because NAP mechanics are unconfirmed', function () {
    expect(is_dir($this->moduleRoot() . '/app/Actions'))->toBeTrue();
    expect($this->resolvableNapActions())->toBe([]);
});

it('exposes no NAP config key, because NAP mechanics are unconfirmed', function () {
    expect($this->napNamed($this->configKeys()))->toBe([]);
});
