<?php

namespace App\Domain;

/** Rollar. Huquqlar matritsasi sozlamalarda (roles.permissions), bu yerda emas. */
enum Role: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case Admin = 'ADMIN';
    case Teacher = 'TEACHER';
    case Student = 'STUDENT';
}
