<?php

namespace App\Domain;

enum Role: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case Admin = 'ADMIN';
    case Teacher = 'TEACHER';
    case Student = 'STUDENT';
    case Parent = 'PARENT';

    private const MATRIX = [
        'SUPER_ADMIN' => ['course.manage', 'course.view', 'group.manage', 'attendance.mark', 'quiz.manage',
            'quiz.take', 'payment.manage', 'payment.view', 'report.view', 'user.manage'],
        'ADMIN' => ['course.manage', 'course.view', 'group.manage', 'attendance.mark', 'quiz.manage',
            'payment.manage', 'payment.view', 'report.view', 'user.manage'],
        'TEACHER' => ['course.manage', 'course.view', 'attendance.mark', 'quiz.manage', 'report.view'],
        'STUDENT' => ['course.view', 'quiz.take', 'payment.view'],
        'PARENT' => ['course.view', 'payment.view'],
    ];

    public function can(string $permission): bool
    {
        return in_array($permission, self::MATRIX[$this->value], true);
    }
}
