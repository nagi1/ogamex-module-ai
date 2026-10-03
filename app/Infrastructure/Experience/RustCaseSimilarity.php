<?php

namespace Modules\AI\Infrastructure\Experience;

use FFI;
use Throwable;

/**
 * The CBRKit driver's similarity measure, computed in-process by the host's Rust library.
 *
 * The function lives next to the battle engine (`rust/battle_engine_ffi/src/case_similarity.rs`) and
 * equals `docker/cognition/cbrkit/retriever.py`, so a ranking from here is the ranking the sidecar
 * would have returned, without an HTTP round trip or a casebase on the wire. Every way it can be
 * unavailable (no FFI extension, a library built before the function existed, a malformed answer)
 * returns null, and the caller then uses the sidecar exactly as before.
 */
class RustCaseSimilarity
{
    /** One binding per process: `FFI::cdef` dlopens the library on every call. false once it failed to load. */
    private static FFI|false|null $binding = null;

    /**
     * @param  array<int, array<string, int|float|string|null>>  $casebase
     * @param  array<string, array<string, int|float|string|null>>  $queries  keyed by a caller-chosen name
     * @return array<string, array<int, float>>|null  similarities per query name
     */
    public function rank(array $casebase, array $queries): array|null
    {
        if (!(bool) config('ai.cognition.experience.cbrkit.rust', true) || $queries === []) {
            return null;
        }

        $ffi = $this->binding();
        if ($ffi === null) {
            return null;
        }

        $input = json_encode(['casebase' => (object) $casebase, 'queries' => (object) array_map(static fn (array $query): object => (object) $query, $queries)]);
        if ($input === false) {
            return null;
        }

        try {
            // @phpstan-ignore-next-line
            $pointer = $ffi->rank_case_similarities($input);
            if ($pointer === null) {
                return null;
            }

            $output = FFI::string($pointer);
            // @phpstan-ignore-next-line
            $ffi->free_battle_result($pointer);
        } catch (Throwable) {
            return null;
        }

        return $this->answers(json_decode($output, true), $casebase, $queries);
    }

    /**
     * Every query must carry a numeric score for every case that was sent: a missing one is a contract
     * deviation, as it is for the sidecar, and the answer is discarded.
     *
     * @param  array<int, array<string, mixed>>  $casebase
     * @param  array<string, mixed>  $queries
     * @return array<string, array<int, float>>|null
     */
    private function answers(mixed $payload, array $casebase, array $queries): array|null
    {
        if (!is_array($payload)) {
            return null;
        }

        $answers = [];

        foreach (array_keys($queries) as $name) {
            $scores = $payload[$name] ?? null;
            if (!is_array($scores)) {
                return null;
            }

            foreach (array_keys($casebase) as $id) {
                $score = $scores[$id] ?? null;
                if (!is_int($score) && !is_float($score)) {
                    return null;
                }
                $answers[$name][$id] = (float) $score;
            }
        }

        return $answers;
    }

    private function binding(): FFI|null
    {
        if (self::$binding === null) {
            self::$binding = $this->load();
        }

        return self::$binding ?: null;
    }

    private function load(): FFI|false
    {
        if (!extension_loaded('ffi')) {
            return false;
        }

        $path = (string) (config('ai.cognition.experience.cbrkit.rust_library') ?: base_path('storage/rust-libs/libbattle_engine_ffi.so'));
        if (!is_file($path)) {
            return false;
        }

        try {
            return FFI::cdef(
                'char* rank_case_similarities(const char* input_json);
                void free_battle_result(char* ptr);',
                $path,
            );
        } catch (Throwable) {
            return false;
        }
    }
}
