<?php

namespace App\Support;

use App\Domain\Role;
use App\Models\User;
use App\Telegram\Notifier;

/**
 * Bot orqali qoldirilgan arizalar (users.pending_at): admin tasdiqlaydi yoki rad etadi.
 * Sayt (UserController) va bot admin paneli (BotAdmin) shu xizmatdan foydalanadi.
 */
final class Registration
{
    /** Ariza orqali berilishi mumkin bo'lgan rollar (admin rollari — faqat foydalanuvchilar bo'limidan). */
    public const ROLES = [Role::Student, Role::Teacher];

    public function __construct(private Notifier $notifier, private Settings $settings) {}

    /** false — ariza allaqachon ko'rib chiqilgan. */
    public function approve(User $user, Role $role, ?int $actorId): bool
    {
        abort_unless(in_array($role, self::ROLES, true), 422, "Bu rolni ariza orqali berib bo'lmaydi.");
        $done = User::whereKey($user->id)->whereNotNull('pending_at')
            ->update(['pending_at' => null, 'active' => true, 'role' => $role->value, 'updated_at' => now()]);
        if (! $done) {
            return false;
        }
        $user->refresh();
        Audit::as($actorId, 'user.approve', 'user', $user->id, ['role' => $role->value], request()?->ip());
        $this->notifier->sendTemplate($user->id, 'registration.approved', 'telegram.text.registration_approved', [
            'name' => $user->first_name, 'role' => $this->roleLabel($role),
        ]);

        return true;
    }

    public function reject(User $user, ?int $actorId): bool
    {
        $done = User::whereKey($user->id)->whereNotNull('pending_at')
            ->update(['pending_at' => null, 'active' => false, 'updated_at' => now()]);
        if (! $done) {
            return false;
        }
        Audit::as($actorId, 'user.reject', 'user', $user->id, [], request()?->ip());
        $this->notifier->sendTemplate($user->id, 'registration.rejected', 'telegram.text.registration_rejected', ['name' => $user->first_name]);

        return true;
    }

    public function roleLabel(Role $role): string
    {
        return (string) (((array) $this->settings->get('telegram.role_labels'))[$role->value] ?? $role->value);
    }
}
