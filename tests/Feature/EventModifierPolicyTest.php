<?php

use Illuminate\Filesystem\Filesystem;

/*
 * The events behaviour entry is unconfirmed: the page behind it states no numbers for event
 * mechanics and no doctrine variant, so an account may not act on event modifiers at all.
 * These tests read the shipped policy the way the pilot does, dotted under the module root,
 * and check the module code that could consume it, so a flipped flag or a modifier path that
 * gets wired in silently fails here instead of quietly changing how an account plays.
 */

const EVENT_MODIFIER_KEY = 'event_modifiers';

function eventsPolicyPath(): string
{
    return dirname(__DIR__, 2) . '/resources/behavior/events.yaml';
}

function eventsPolicyContents(): string
{
    $path = eventsPolicyPath();

    expect(is_file($path))->toBeTrue();

    return (string) file_get_contents($path);
}

function eventsPolicyEntry(): array
{
    $matched = preg_match_all('/^[ \t]+([a-z_]+):[ \t]*(.*)$/m', eventsPolicyContents(), $lines);

    if ($matched === false || $matched === 0) {
        return [];
    }

    return array_combine($lines[1], $lines[2]);
}

it('records the events entry as unconfirmed', function () {
    expect(eventsPolicyEntry()['confirmed'])->toBe('false');
});

it('ships no event modifier value for a schedule to consume', function () {
    expect(eventsPolicyEntry()[EVENT_MODIFIER_KEY])->toBe('[]');
});

it('is not consumed by any planner, engine or action in the module', function () {
    $moduleRoot = dirname(__DIR__, 2);
    $readers = [];

    foreach ((new Filesystem())->allFiles($moduleRoot . '/app') as $file) {
        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, EVENT_MODIFIER_KEY)) {
            $readers[] = $file->getRelativePathname();
        }
    }

    expect($readers)->toBe([]);
});
