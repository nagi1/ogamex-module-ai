<?php

use Illuminate\Foundation\Application;
use Modules\AI\Domain\Coordinate;
use Modules\AI\Exceptions\InvalidCoordinateException;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

/**
 * The plan filed this test under tests/Unit, which this module does not accept, so the coordinate is
 * exercised with the module's application booted.
 */
class CoordinateModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(CoordinateModuleTestCase::class);

it('parses a galaxy:system:position string into its three components', function (): void {
    $coordinate = Coordinate::fromString('3:47:12');

    expect($coordinate->galaxy)->toBe(3)
        ->and($coordinate->system)->toBe(47)
        ->and($coordinate->position)->toBe(12);
});

it('formats a coordinate back into the identical string', function (): void {
    expect(Coordinate::fromString('3:47:12')->format())->toBe('3:47:12');
});

it('equals another instance built from the same string and nothing else', function (): void {
    $coordinate = Coordinate::fromString('3:47:12');

    expect($coordinate->equals(Coordinate::fromString('3:47:12')))->toBeTrue()
        ->and($coordinate->equals(Coordinate::fromString('3:47:13')))->toBeFalse()
        ->and($coordinate->equals(Coordinate::fromString('3:48:12')))->toBeFalse()
        ->and($coordinate->equals(Coordinate::fromString('4:47:12')))->toBeFalse();
});

it('accepts every part from one upwards, because the shape states no upper bound', function (string $value): void {
    expect(Coordinate::fromString($value)->format())->toBe($value);
})->with([
    'smallest parts' => '1:1:1',
    'large parts' => '9:99:999',
    'very large parts' => '12:345:6789',
]);

it('rejects a value that is not a galaxy:system:position coordinate', function (string $value): void {
    expect(fn () => Coordinate::fromString($value))->toThrow(InvalidCoordinateException::class);
})->with([
    'empty string' => '',
    'too few parts' => '3:47',
    'too many parts' => '3:47:12:9',
    'non numeric parts' => 'a:b:c',
    'zero position' => '3:47:0',
    'zero system' => '3:0:12',
    'zero galaxy' => '0:47:12',
    'leading zero in a part' => '3:47:012',
    'separators only' => '::',
    'trailing separator' => '3:47:',
    'whitespace around a part' => '3:47: 12',
]);
