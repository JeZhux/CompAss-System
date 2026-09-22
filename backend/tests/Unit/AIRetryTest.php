<?php

namespace Tests\Unit;

use App\Exceptions\AIServiceException;
use App\Services\AIService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * S-7: one bounded retry on transient AI failures, fail-closed preserved.
 *
 * callOpenRouterAPI performs at most 2 attempts total (initial + 1 retry)
 * with the same 10 s timeout per attempt. Only transient failures — a
 * connection timeout/failure or HTTP 429/5xx — are retried once. Any other
 * failure (other 4xx, malformed/empty body, missing key) throws
 * 503 AI_SERVICE_UNAVAILABLE immediately with no retry. The mock gate
 * short-circuits before any network I/O.
 *
 * All upstream I/O is faked with Http::fake sequences — these tests never
 * touch the network.
 *
 * @Traced-To S-7 (U-09)
 */
class AIRetryTest extends TestCase
{
    private AIService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouter.mock' => false,
            'services.openrouter.api_key' => 'test-key',
        ]);

        $this->service = app(AIService::class);
    }

    /** @test */
    public function test_transient_timeout_then_success_returns_explanation_after_two_attempts()
    {
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->pushFailedConnection('Connection timeout')
                ->push(['choices' => [['message' => ['content' => 'ok']]]], 200),
        ]);

        $result = $this->service->callOpenRouterAPI('some prompt');

        $this->assertTrue($result['success']);
        $this->assertSame('ok', $result['explanation']);
        Http::assertSentCount(2);
    }

    /** @test */
    public function test_persistent_500_throws_503_after_exactly_two_attempts()
    {
        Http::fake([
            // Exactly 2 responses queued: a third attempt would exhaust the
            // sequence (OutOfBoundsException), so reaching the 503 below via
            // AIServiceException proves the call stopped at 2 (never 3+).
            'openrouter.ai/*' => Http::sequence()
                ->pushStatus(500)
                ->pushStatus(500),
        ]);

        try {
            $this->service->callOpenRouterAPI('some prompt');
            $this->fail('Expected AIServiceException was not thrown.');
        } catch (AIServiceException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('AI_SERVICE_UNAVAILABLE', $e->errorCode);
        }

        Http::assertSentCount(2);
    }

    /** @test */
    public function test_malformed_response_throws_503_without_retry()
    {
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push(['unexpected' => 'shape'], 200),
        ]);

        try {
            $this->service->callOpenRouterAPI('some prompt');
            $this->fail('Expected AIServiceException was not thrown.');
        } catch (AIServiceException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('AI_SERVICE_UNAVAILABLE', $e->errorCode);
        }

        Http::assertSentCount(1);
    }

    /** @test */
    public function test_missing_key_throws_503_without_network_attempt()
    {
        config(['services.openrouter.api_key' => null]);

        Http::fake();

        try {
            $this->service->callOpenRouterAPI('some prompt');
            $this->fail('Expected AIServiceException was not thrown.');
        } catch (AIServiceException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('AI_SERVICE_UNAVAILABLE', $e->errorCode);
        }

        Http::assertSentCount(0);
    }

    /** @test */
    public function test_mock_gate_returns_mock_without_network_attempt()
    {
        config([
            'services.openrouter.mock' => true,
            'services.openrouter.mock_response' => 'mocked explanation',
        ]);

        Http::fake();

        $result = $this->service->callOpenRouterAPI('some prompt');

        $this->assertTrue($result['success']);
        $this->assertSame('mocked explanation', $result['explanation']);
        Http::assertSentCount(0);
    }
}
