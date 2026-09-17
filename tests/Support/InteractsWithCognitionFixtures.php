<?php

namespace Modules\AI\Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Replays what the optional cognition sidecars really answered.
 *
 * The recordings in `tests/Fixtures/cognition/` are captured from the running containers by
 * `scripts/capture-cognition-fixtures.php`, not written by hand: a response literal in a test
 * can drift from the driver's wire format and leave the suite green while production parsing
 * breaks. Replaying the real bodies keeps the module's parsers under test without a live
 * sidecar, so a suite run never depends on a container being up.
 *
 * Two things a replay must not do, and both are enforced here rather than left to the test:
 *
 * - It must not answer a request the recording does not cover. A recorded ranking belongs to a
 *   specific query and memory content, so a fake that answered anything would let a test pass
 *   against a driver response that driver would never give. Every fake below matches the live
 *   request against the recorded one and throws when it does not match.
 * - It must not invent identities. FAtiMA answers about an authored character, so its bodies
 *   transfer unchanged, but CBRKit keys its scores by case id and AgentOS ranks memory ids, and
 *   those ids are the test database's own. Those two replay the recorded *decision* against the
 *   module ids present in the request, matching by the case features and the memory text the
 *   driver actually scored.
 *
 * Behaviour no real sidecar can produce — a contract violation, an unreachable value, an
 * oversized body — is not captured here. `driverProbe()` reads those from
 * `tests/Fixtures/cognition/probes.json`, which is authored and says for each entry why it
 * cannot be a recording.
 */
trait InteractsWithCognitionFixtures
{
    /** @var array<string, array<string, mixed>> */
    private static array $cognitionFixtures = [];

    /**
     * Replays the captured FAtiMA sidecar.
     *
     * The driver is a calculator: what it answers depends on the beliefs and the event it was
     * just given, so this fake tracks them, exactly as the sidecar does. Its thresholds are
     * therefore real — a rapport the module derives as 3 or less really does withhold the
     * exchange — instead of a test asserting the answer it already assumed.
     *
     * @param  array<string, PromiseInterface|Response>  $override  one `probes.json` entry per
     *                                                                endpoint, for the contract
     *                                                                violations a real sidecar
     *                                                                cannot produce; the `*` key
     *                                                                answers every endpoint at once
     */
    protected function fakeFatimaDriver(array $override = []): void
    {
        $channels = $this->cognitionFixture('fatima')['channels'];
        $state = ['event' => null, 'beliefs' => []];
        // A driver answering every call with the same failure (a tripped contract, a dead
        // sidecar) is one override, not five.
        $override += array_fill_keys(['scenarios', 'beliefs', 'perceptions', 'emotions', 'socialexchanges'], $override['*'] ?? null);
        $for = static fn (string $channel): PromiseInterface|Response|null => $override[$channel] ?? null;

        Http::fake([
            '*/scenarios' => fn (): PromiseInterface|Response => $for('scenarios') ?? Http::response($channels['scenario_created']),
            '*/beliefs' => function (Request $request) use (&$state, $channels, $for): PromiseInterface|Response {
                $state['beliefs'][$request['name']] = (string) $request['value'];

                return $for('beliefs') ?? Http::response($channels['belief_updated']);
            },
            '*/perceptions' => function (Request $request) use (&$state, $channels, $for): PromiseInterface|Response {
                $state['event'] = (string) ($request->data()[0] ?? '');

                return $for('perceptions') ?? Http::response($channels['perception_perceived']);
            },
            // Both of these read the tracked state, so they capture it by reference: an arrow
            // function would freeze it at the value it held when the fake was installed.
            '*/emotions' => function () use (&$state, $channels, $for): PromiseInterface|Response {
                return $for('emotions') ?? Http::response($this->fatimaRecordedEmotions($channels['emotions'], $state));
            },
            '*/socialexchanges' => function () use (&$state, $channels, $for): PromiseInterface|Response {
                return $for('socialexchanges') ?? Http::response($this->fatimaRecordedExchange($channels['socialexchanges'], $state));
            },
        ]);
    }

    /**
     * Replays the captured CBRKit sidecar.
     *
     * Pass a `driverProbe()` response to answer with something the retriever would never send.
     */
    protected function fakeCbrKitDriver(PromiseInterface|Response|null $response = null): void
    {
        Http::fake(['*/retrieve' => function (Request $request) use ($response): PromiseInterface|Response {
            if ($response !== null) {
                return $response;
            }

            $casebase = $request->data()['casebase'] ?? null;

            // The retriever validates the casebase before it scores anything, so a malformed one
            // is refused rather than scored. The command's failure probe sends exactly that.
            return is_array($casebase) && array_filter($casebase, 'is_array') === $casebase
                ? $this->cbrKitFixtureResponse($request)
                : $this->cbrKitRefusal();
        }]);
    }

    /**
     * The recorded envelope with the one answer the retriever cannot give: each case scored by the
     * position it was sent in, which inverts the module's own ordering for a tied casebase.
     *
     * A probe rather than a recording, because the retriever's measure is categorical on the
     * object, so it scores every case for one object equally and can never disagree with the
     * module about the conformance fixture.
     */
    protected function fakeCbrKitDisagreeingDriver(): void
    {
        Http::fake(['*/retrieve' => function (Request $request): PromiseInterface|Response {
            $envelope = $this->cognitionFixture('cbrkit')['channels']['retrieve']['conformance_tie']['body'];
            $casebase = $request->data()['casebase'] ?? [];
            $similarities = [];

            foreach (array_keys(is_array($casebase) ? $casebase : []) as $position => $id) {
                $similarities[(string) $id] = (float) $position;
            }

            $envelope['steps'][0]['queries']['current']['similarities'] = $similarities;

            return Http::response($envelope);
        }]);
    }

    /** The retriever's real answer for a casebase it cannot read. */
    private function cbrKitRefusal(): PromiseInterface|Response
    {
        $refusal = $this->cognitionFixture('cbrkit')['channels']['refused_casebase'];

        return Http::response($refusal['body'], $refusal['status']);
    }

    /**
     * The recorded answer for one request.
     *
     * The HTTP fake appends its stubs and the first match wins, so a test that has to alternate a
     * real answer with a failure installs one fake and calls this from inside it, rather than
     * faking twice and silently keeping the first stub.
     */
    protected function cbrKitFixtureResponse(Request $request): PromiseInterface|Response
    {
        return Http::response($this->cbrKitRanking($request->data()));
    }

    /**
     * Replays the captured AgentOS sidecar.
     *
     * Pass a `driverProbe()` response to answer with something the driver would never send.
     */
    protected function fakeAgentOsDriver(PromiseInterface|Response|null $response = null): void
    {
        Http::fake(['*/recall' => fn (Request $request): PromiseInterface|Response => $response
            ?? $this->agentOsFixtureResponse($request)]);
    }

    /**
     * The recorded answer for one request, for a test that has to mix it with a failure.
     */
    protected function agentOsFixtureResponse(Request $request): PromiseInterface|Response
    {
        $data = $request->data();

        return Http::response($this->agentOsRanking($this->agentOsRecording((string) ($data['query'] ?? '')), $data));
    }

    /**
     * The recording that answers one query, or a failure. The driver's ranking is what the query
     * makes it, so a recording is an answer to one question and never a general one.
     *
     * @return array{request: array{query: string, memories: list<array{text: string}>}, body: array<string, mixed>}
     */
    private function agentOsRecording(string $query): array
    {
        foreach ($this->cognitionFixture('agentos')['channels']['recall'] as $recording) {
            if ($recording['request']['query'] === $query) {
                return $recording;
            }
        }

        throw new RuntimeException(sprintf(
            'The AgentOS fixture has no recall recorded for the query "%s"; re-run the capture script with it.',
            $query,
        ));
    }

    /**
     * A recorded behaviour the real sidecar cannot produce, so it can only be authored. The
     * entry in `probes.json` states why, and each one is a contract violation, a value the
     * authored scenario cannot reach, or a body past the module's own read bound.
     *
     * A `@token` inside the probe's body is replaced by the value the test supplies, which is how
     * a probe can refer to a record of its own — a duplicate id, for instance, has to be an id
     * the request actually carried or it would be refused for being unknown instead.
     *
     * @param  array<string, int|string>  $tokens
     */
    protected function driverProbe(string $key, array $tokens = []): PromiseInterface|Response
    {
        $probe = $this->cognitionFixture('probes')['channels'][$key] ?? throw new RuntimeException(
            sprintf('The cognition probe fixture has no "%s" entry.', $key),
        );

        return Http::response($this->withTokens($probe['body'], $tokens), $probe['status'] ?? 200);
    }

    /**
     * @param  array<string, int|string>  $tokens
     */
    private function withTokens(mixed $value, array $tokens): mixed
    {
        if (is_string($value)) {
            return $tokens[$value] ?? $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $replaced = [];

        foreach ($value as $key => $item) {
            $replaced[is_string($key) ? ($tokens[$key] ?? $key) : $key] = $this->withTokens($item, $tokens);
        }

        return $replaced;
    }

    /** @return array<string, mixed> */
    protected function cognitionFixture(string $name): array
    {
        return self::$cognitionFixtures[$name] ??= json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/cognition/'.$name.'.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The appraisal the driver really returned for this event and belief pair, or a failure —
     * the recording is the driver's answer to one specific question, never a general one.
     *
     * @param  array<string, array{request: array{event: string, beliefs: array<string, string>}, body: mixed}>  $recordings
     * @param  array{event: string|null, beliefs: array<string, string>}  $state
     */
    private function fatimaRecordedEmotions(array $recordings, array $state): mixed
    {
        foreach ($recordings as $recording) {
            $expected = $recording['request']['beliefs'];

            // Only the recorded stimulus beliefs are compared: a rapport write from an earlier
            // social evaluation in the same test is not part of this appraisal's question.
            if ($recording['request']['event'] === $state['event']
                && array_intersect_key($state['beliefs'], $expected) == $expected) {
                return $recording['body'];
            }
        }

        throw new RuntimeException(sprintf(
            'The FAtiMA fixture has no appraisal for the event "%s" with beliefs %s; re-run the capture script with that branch.',
            (string) $state['event'],
            json_encode($state['beliefs'], JSON_THROW_ON_ERROR),
        ));
    }

    /**
     * The exchange the driver really returned at this rapport, or a failure. CiF gates the
     * exchange on the rapport belief, so answering without one would assert a threshold.
     *
     * @param  array<string, array{request: array{target: string, rapport: string}, body: mixed}>  $recordings
     * @param  array{event: string|null, beliefs: array<string, string>}  $state
     */
    private function fatimaRecordedExchange(array $recordings, array $state): mixed
    {
        foreach ($state['beliefs'] as $name => $value) {
            if (!str_starts_with($name, 'RapportLevel(SELF, ')) {
                continue;
            }

            return $recordings['rapport-'.$value]['body'] ?? throw new RuntimeException(sprintf(
                'The FAtiMA fixture has no social exchange recorded at rapport %s.',
                $value,
            ));
        }

        throw new RuntimeException('The FAtiMA driver was asked for a social exchange without a rapport belief.');
    }

    /**
     * The driver's recorded scores, rebound to the case ids this request carries.
     *
     * The retriever is stateless and a case's score depends only on the case's features and the
     * query, so the fixture is an index of (query, features) to score rather than a log of
     * requests: the recorded score for a case transfers to whatever id the module stored the same
     * case under. A case the fixture holds no score for is a failure rather than a zero, because
     * a fabricated score would be read as evidence.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function cbrKitRanking(array $request): array
    {
        $query = $request['queries']['current'] ?? null;
        $scores = [];
        $envelope = null;

        foreach ($this->cognitionFixture('cbrkit')['channels']['retrieve'] as $recording) {
            if ($recording['request']['queries']['current'] != $query) {
                continue;
            }

            $recorded = $recording['body']['steps'][0]['queries']['current']['similarities'];

            foreach ($recording['request']['casebase'] as $id => $features) {
                $scores[$this->featureKey($features)] = (float) $recorded[$id];
            }

            // Every recording of one query carries the same envelope.
            $envelope ??= $recording['body'];
        }

        if ($envelope === null) {
            throw new RuntimeException('The CBRKit fixture has no retrieval recorded for the query: ' . json_encode($query, JSON_THROW_ON_ERROR));
        }

        $rebound = [];

        foreach ($request['casebase'] as $id => $features) {
            $rebound[(string) $id] = $scores[$this->featureKey($features)] ?? throw new RuntimeException(
                'The CBRKit fixture has no case recorded with these features under this query: ' . json_encode($features, JSON_THROW_ON_ERROR),
            );
        }

        // The recorded envelope is replayed verbatim apart from the case ids it is keyed by; the
        // ranking and casebase it echoes are not read by the module.
        $envelope['steps'][0]['queries']['current']['similarities'] = $rebound;

        return $envelope;
    }

    /**
     * One case's features as an order-independent key, so two cases carrying the same features are
     * one measurement.
     *
     * @param  array<string, mixed>  $features
     */
    private function featureKey(array $features): string
    {
        ksort($features);

        return json_encode($features, JSON_THROW_ON_ERROR);
    }

    /**
     * The memory texts one recorded query ranked, in the driver's own order.
     *
     * The driver's tie-break between equally relevant memories is its own and a re-capture can
     * legitimately return a different order, so a test asserts that the module followed the
     * recording rather than a literal the next capture would falsify.
     *
     * @return list<string>
     */
    protected function agentOsRankedTexts(string $query): array
    {
        $recording = $this->agentOsRecording($query);
        $textById = [];

        foreach ($recording['request']['memories'] as $memory) {
            $textById[$memory['id']] = $memory['text'];
        }

        return array_map(static fn (array $entry): string => $textById[$entry['id']], $recording['body']['ranking']);
    }

    /**
     * The driver's recorded ranking, rebound to the memory ids this request carries.
     *
     * The driver ranks the text it is given, so the recorded order transfers to whichever fact
     * carries the same text. A memory text the recording does not cover is a failure: a ranking of
     * a different candidate set is not this driver's answer.
     *
     * @param  array{request: array{query: string, memories: list<array{text: string}>}, body: array<string, mixed>}  $recording
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function agentOsRanking(array $recording, array $request): array
    {
        $byText = [];

        foreach ($request['memories'] as $memory) {
            $byText[$memory['text']] = $memory['id'];
        }

        $recordedText = [];

        foreach ($recording['request']['memories'] as $memory) {
            $recordedText[$memory['id']] = $memory['text'];
        }

        // The driver ranks the candidate set it is handed, so a recording only answers that set.
        // Replaying it against a different one would invent a ranking the driver never produced.
        $sent = array_keys($byText);
        $recorded = array_values($recordedText);
        sort($sent);
        sort($recorded);

        if ($sent !== $recorded) {
            throw new RuntimeException('The AgentOS fixture recorded a different candidate set: ' . json_encode(array_values($byText), JSON_THROW_ON_ERROR));
        }

        $ranking = [];

        foreach ($recording['body']['ranking'] as $entry) {
            $text = $recordedText[$entry['id']] ?? throw new RuntimeException('The AgentOS fixture ranked an id it did not record.');
            $entry['id'] = $byText[$text];

            $ranking[] = $entry;
        }

        // The driver answers at most `limit` ids — measured against the live sidecar, a limit of 2
        // over three candidates returns two — so a replay must not hand the module a longer ranking
        // than the request asked for. The recordings are captured at the whole candidate set, which
        // is what lets one recording answer any smaller limit.
        $body = $recording['body'];
        $body['ranking'] = array_slice($ranking, 0, max(0, (int) ($request['limit'] ?? count($ranking))));

        return $body;
    }
}
