<?php
// Compares the Rust case similarity (rank_case_similarities in the host's libbattle_engine_ffi.so) with the
// CBRKit retriever's own Python measure on 60,000 random (case, query) pairs, including nulls, text, bools,
// identity ids as ints/floats/strings and unknown features. Any mismatch above 1e-12 fails the check.
//
//   python3 scripts/rust-similarity-check/gen.py
//   php scripts/rust-similarity-check/check.php /path/to/libbattle_engine_ffi.so
$library = $argv[1] ?? 'storage/rust-libs/libbattle_engine_ffi.so';
$d = json_decode(file_get_contents(__DIR__ . '/data.json'), true);
$ffi = FFI::cdef('char* rank_case_similarities(const char* i); void free_battle_result(char* p);', $library);
$in = json_encode(['casebase' => (object) $d['casebase'], 'queries' => (object) array_map(fn ($q) => (object) $q, $d['queries'])]);
$p = $ffi->rank_case_similarities($in);
if ($p === null) {
    fwrite(STDERR, "FAIL: the library returned null (is it built from the commit with case_similarity.rs?)\n");
    exit(1);
}
$out = json_decode(FFI::string($p), true);
$ffi->free_battle_result($p);
$max = 0.0; $n = 0; $bad = 0;
foreach ($d['expected'] as $q => $scores) {
    foreach ($scores as $id => $expected) {
        $got = $out[$q][$id] ?? null; $n++;
        if ($got === null) { $bad++; continue; }
        $diff = abs($got - $expected); $max = max($max, $diff);
        if ($diff > 1e-12) { $bad++; }
    }
}
printf("compared %d scores, max abs diff %.3g, mismatches %d\n", $n, $max, $bad);
exit($bad === 0 ? 0 : 1);
