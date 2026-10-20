import { describe, expect, it } from "vitest";
import {
  applyDiscount, attendanceRate, balance, can, canSubmit, certificateEligibility,
  classifyArrival, gradeQuiz, proratedMonthlyFee, type Question,
} from "./index.ts";

const t = (min: number) => new Date(Date.UTC(2026, 9, 20, 9, min));

describe("roles", () => {
  it("o'quvchi kurs boshqara olmaydi", () => {
    expect(can("STUDENT", "course.manage")).toBe(false);
    expect(can("TEACHER", "attendance.mark")).toBe(true);
  });
});

describe("attendance", () => {
  it("kelish vaqtini tasniflaydi", () => {
    expect(classifyArrival(t(0), t(3))).toBe("PRESENT");
    expect(classifyArrival(t(0), t(10))).toBe("LATE");
    expect(classifyArrival(t(0), t(45))).toBe("ABSENT");
    expect(classifyArrival(t(0), null)).toBe("ABSENT");
  });
  it("sababli dars foizga kirmaydi", () => {
    expect(attendanceRate(["PRESENT", "LATE", "ABSENT", "EXCUSED"])).toBe(66.7);
    expect(attendanceRate([])).toBe(100);
  });
});

describe("quiz", () => {
  const qs: Question[] = [
    { id: "a", type: "SINGLE", points: 2, correct: ["a1"] },
    { id: "b", type: "MULTIPLE", points: 3, correct: ["b1", "b2"] },
    { id: "c", type: "TEXT", points: 5, correct: ["Toshkent"] },
  ];
  it("to'liq to'g'ri javob", () => {
    const r = gradeQuiz(qs, { a: ["a1"], b: ["b2", "b1"], c: ["  toshkent "] }, { passPercent: 60 });
    expect(r).toEqual({ score: 10, maxScore: 10, percent: 100, passed: true });
  });
  it("MULTIPLE da qisman ball yo'q", () => {
    const r = gradeQuiz(qs, { a: ["a1"], b: ["b1"] }, { passPercent: 60 });
    expect(r.score).toBe(2);
    expect(r.passed).toBe(false);
  });
  it("urinish va vaqt cheklovi", () => {
    const rules = { passPercent: 60, maxAttempts: 2, timeLimitSec: 600 };
    expect(canSubmit(rules, 2, t(0), t(1))).toEqual({ ok: false, reason: "NO_ATTEMPTS_LEFT" });
    expect(canSubmit(rules, 0, t(0), t(11))).toEqual({ ok: false, reason: "TIME_EXPIRED" });
    expect(canSubmit(rules, 1, t(0), t(9))).toEqual({ ok: true });
  });
});

describe("billing", () => {
  it("chegirma va proratsiya", () => {
    expect(applyDiscount(500_000, { kind: "PERCENT", value: 10 })).toBe(450_000);
    expect(applyDiscount(100_000, { kind: "FIXED", value: 150_000 })).toBe(0);
    expect(proratedMonthlyFee(600_000, 12, 6)).toBe(300_000);
  });
  it("balans: qarz musbat", () => {
    expect(balance([{ type: "INVOICE", amount: 500_000 }, { type: "PAYMENT", amount: 300_000 }])).toBe(200_000);
  });
});

describe("certificate", () => {
  it("barcha shartlar", () => {
    expect(certificateEligibility({ attendanceRate: 80, finalQuizPassed: true, debt: 0 })).toEqual({ ok: true });
    expect(certificateEligibility({ attendanceRate: 50, finalQuizPassed: false, debt: 1 })).toEqual({
      ok: false, reasons: ["LOW_ATTENDANCE", "QUIZ_NOT_PASSED", "HAS_DEBT"],
    });
  });
});
