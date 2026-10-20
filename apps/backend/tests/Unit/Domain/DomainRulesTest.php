<?php

namespace Tests\Unit\Domain;

use App\Domain\Attendance;
use App\Domain\Billing;
use App\Domain\CertificatePolicy;
use App\Domain\QuizGrader;
use App\Domain\Role;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class DomainRulesTest extends TestCase
{
    private function t(int $min): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-10-20 09:%02d:00', $min));
    }

    public function test_roles(): void
    {
        $this->assertFalse(Role::Student->can('course.manage'));
        $this->assertTrue(Role::Teacher->can('attendance.mark'));
    }

    public function test_attendance_classification(): void
    {
        $this->assertSame('PRESENT', Attendance::classifyArrival($this->t(0), $this->t(3)));
        $this->assertSame('LATE', Attendance::classifyArrival($this->t(0), $this->t(10)));
        $this->assertSame('ABSENT', Attendance::classifyArrival($this->t(0), $this->t(45)));
        $this->assertSame('ABSENT', Attendance::classifyArrival($this->t(0), null));
    }

    public function test_attendance_rate_excludes_excused(): void
    {
        $this->assertSame(66.7, Attendance::rate(['PRESENT', 'LATE', 'ABSENT', 'EXCUSED']));
        $this->assertSame(100.0, Attendance::rate([]));
    }

    public function test_quiz_grading(): void
    {
        $qs = [
            'a' => ['type' => 'SINGLE', 'points' => 2, 'correct' => ['a1']],
            'b' => ['type' => 'MULTIPLE', 'points' => 3, 'correct' => ['b1', 'b2']],
            'c' => ['type' => 'TEXT', 'points' => 5, 'correct' => ['Toshkent']],
        ];
        $full = QuizGrader::grade($qs, ['a' => ['a1'], 'b' => ['b2', 'b1'], 'c' => ['  toshkent ']], 60);
        $this->assertSame(['score' => 10, 'max_score' => 10, 'percent' => 100.0, 'passed' => true], $full);

        $partial = QuizGrader::grade($qs, ['a' => ['a1'], 'b' => ['b1']], 60);
        $this->assertSame(2, $partial['score']);
        $this->assertFalse($partial['passed']);
    }

    public function test_quiz_submit_limits(): void
    {
        $this->assertSame('NO_ATTEMPTS_LEFT', QuizGrader::submitBlocker(2, 600, 2, $this->t(0), $this->t(1)));
        $this->assertSame('TIME_EXPIRED', QuizGrader::submitBlocker(2, 600, 0, $this->t(0), $this->t(11)));
        $this->assertNull(QuizGrader::submitBlocker(2, 600, 1, $this->t(0), $this->t(9)));
    }

    public function test_billing(): void
    {
        $this->assertSame(450_000, Billing::applyDiscount(500_000, 'PERCENT', 10));
        $this->assertSame(0, Billing::applyDiscount(100_000, 'FIXED', 150_000));
        $this->assertSame(300_000, Billing::proratedMonthlyFee(600_000, 12, 6));
        $this->assertSame(200_000, Billing::balance([
            ['type' => 'INVOICE', 'amount' => 500_000],
            ['type' => 'PAYMENT', 'amount' => 300_000],
        ]));
    }

    public function test_certificate_policy(): void
    {
        $this->assertSame([], CertificatePolicy::blockers(80, true, 0));
        $this->assertSame(['LOW_ATTENDANCE', 'QUIZ_NOT_PASSED', 'HAS_DEBT'], CertificatePolicy::blockers(50, false, 1));
    }
}
