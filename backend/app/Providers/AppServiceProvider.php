<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Services\AnnouncementService;
use App\Services\AssessmentService;
use App\Services\AssignmentService;
use App\Services\AuditLogService;
use App\Services\BatchImportService;
use App\Services\AIService;
use App\Services\AnalyticsService;
use App\Services\ClassroomService;
use App\Services\CompetencyMappingService;
use App\Services\GradingService;
use App\Services\LearningMaterialService;
use App\Services\OrgStructureService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AuditLogService is a stateless append-only utility: the bound AuditLog
        // instance is only used as an insert prototype via newInstance() and is
        // never mutated, so a singleton is safe (ARCH-002 QA-006, ARCH-002 QA-004; ARCH-001 §5.1).
        $this->app->singleton(AuditLogService::class, fn () => new AuditLogService(new AuditLog()));
        $this->app->singleton(BatchImportService::class);
        $this->app->singleton(AnnouncementService::class);
        $this->app->singleton(AssignmentService::class);
        $this->app->singleton(AssessmentService::class);
        $this->app->singleton(GradingService::class);
        $this->app->singleton(CompetencyMappingService::class);
        $this->app->singleton(AnalyticsService::class);
        $this->app->singleton(LearningMaterialService::class);
        $this->app->singleton(AIService::class);
        // OrgStructureService is stateless apart from its injected
        // AuditLogService singleton — safe to share (matches the other
        // service singletons registered above).
        $this->app->singleton(OrgStructureService::class);
        $this->app->singleton(ClassroomService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Global API backstop (120/min per IP): applies via throttle:api on
        // the api middleware group. Named limiters below stay untouched.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // ARCH-002 QA-003/QA-006 per-address throttles (spec §23 limitation 12): generous per-IP throttles for the
        // two unbounded endpoint families — AI explain-further (30/min) and
        // batch-import uploads (10/min). Same per-IP keying pattern as the
        // login throttle, which is code-enforced in AuthService::authenticate
        // via RateLimiter (5 attempts per 15 min per IP+account, ARCH-002 QA-004)
        // rather than a named limiter here; budgets sized for legitimate use.
        // ai-chat: per-student keying, never IP (ARCH-002 QA-003) — mirrors
        // ai-generate so a class behind shared NAT cannot exhaust each
        // other's 30/min budget. Limit unchanged (30/min); the global
        // per-IP api backstop (120/min) above still bounds abusive IPs.
        RateLimiter::for(
            'ai-chat',
            fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id)
        );
        // import: per-admin keying, never IP (ARCH-002 QA-003 per-user limits
        // convention, same as ai-chat/ai-generate/moderation-write/join/
        // classroom-key above) — admins behind shared NAT must not exhaust
        // each other's 10/min budget. The global per-IP api backstop
        // (120/min) above still bounds abusive IPs.
        RateLimiter::for('import', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));

        // Explicit student-initiated explanation generation is keyed
        // PER-STUDENT identity, never IP (ARCH-002 QA-003 per-student request isolation): a class requesting behind
        // shared NAT must not be able to block or fail each other's requests.
        // ai-generate: per-student keying, never IP (ARCH-002 QA-003).
        RateLimiter::for(
            'ai-generate',
            fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id)
        );

        // Moderation-log writes (#92/#93/#94) are rate-limited per
        // Requirements §2.7 ("60 writes per minute per module"). Keyed
        // PER-TEACHER identity, same convention as ai-generate: "per module"
        // is deliberately interpreted as per-client so one teacher cannot
        // exhaust every teacher's shared budget.
        // moderation-write: per-teacher keying (ARCH-002 QA-003/QA-006 per-user limits convention).
        RateLimiter::for(
            'moderation-write',
            fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()?->id)
        );

        // U02 — Classroom join and join-key rotation throttles.
        // join: student joining via key is keyed PER-STUDENT identity (same
        // fairness rationale as ai-generate ARCH-002 QA-003: shared NAT must not block).
        // classroom-key: teacher/admin classroom creation and key rotation
        // keyed PER-TEACHER/ADMIN identity.
        // join: per-student keying (ARCH-002 QA-003).
        RateLimiter::for(
            'join',
            fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->id)
        );

        // classroom-key: per-teacher/admin keying (ARCH-002 QA-003 per-user limits convention).
        RateLimiter::for(
            'classroom-key',
            fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id)
        );
    }
}
