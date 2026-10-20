export const ROLES = ["SUPER_ADMIN", "ADMIN", "TEACHER", "STUDENT", "PARENT"] as const;
export type Role = (typeof ROLES)[number];

export type Permission =
  | "course.manage"
  | "course.view"
  | "group.manage"
  | "attendance.mark"
  | "quiz.manage"
  | "quiz.take"
  | "payment.manage"
  | "payment.view"
  | "report.view"
  | "user.manage";

const MATRIX: Record<Role, readonly Permission[]> = {
  SUPER_ADMIN: [
    "course.manage", "course.view", "group.manage", "attendance.mark", "quiz.manage",
    "quiz.take", "payment.manage", "payment.view", "report.view", "user.manage",
  ],
  ADMIN: [
    "course.manage", "course.view", "group.manage", "attendance.mark", "quiz.manage",
    "payment.manage", "payment.view", "report.view", "user.manage",
  ],
  TEACHER: ["course.manage", "course.view", "attendance.mark", "quiz.manage", "report.view"],
  STUDENT: ["course.view", "quiz.take", "payment.view"],
  PARENT: ["course.view", "payment.view"],
};

export function can(role: Role, permission: Permission): boolean {
  return MATRIX[role].includes(permission);
}
