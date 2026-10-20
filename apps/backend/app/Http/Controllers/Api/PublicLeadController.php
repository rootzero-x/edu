<?php

namespace App\Http\Controllers\Api;

use App\Models\Course;
use App\Support\Audit;
use App\Support\Leads;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Bosh sahifadagi "Bepul sinov darsiga yoziling" formasi. Login talab qilinmaydi.
 * Spamdan himoya: IP va telefon bo'yicha limit (throttle:leads), yashirin "website" maydoni (honeypot) va
 * formani to'ldirish vaqti (leads.min_fill_seconds). Bot deb topilsa — muvaffaqiyat javobi qaytadi, lekin ariza yozilmaydi.
 */
class PublicLeadController
{
    public function store(Request $request, Settings $settings, Leads $leads): JsonResponse
    {
        abort_unless($settings->bool('leads.form_enabled'), 404);
        $labels = (array) $settings->get('leads.form_labels');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:128'],
            'phone' => ['required', 'string', 'max:32'],
            'course_id' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'consent' => ['nullable', 'boolean'],
            'website' => ['nullable', 'string', 'max:255'],
            'fill_ms' => ['nullable', 'integer', 'min:0'],
            'utm' => ['nullable', 'array:source,medium,campaign'],
            'utm.*' => ['nullable', 'string', 'max:100'],
        ], [
            'name.required' => $labels['name_required'] ?? 'Ismingizni kiriting',
            'name.min' => $labels['name_required'] ?? 'Ismingizni kiriting',
        ]);
        $ok = response()->json(['ok' => true, 'message' => $settings->get('leads.success_text')], 201);

        // Honeypot yoki juda tez to'ldirilgan forma — bot. Xatoni oshkor qilmaymiz.
        if (trim((string) ($data['website'] ?? '')) !== '' || ($data['fill_ms'] ?? 0) < $settings->int('leads.min_fill_seconds') * 1000) {
            return $ok;
        }
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => $labels['phone_invalid'] ?? "Telefon raqami noto'g'ri."]);
        }
        if ($settings->bool('leads.consent_required') && ! ($data['consent'] ?? false)) {
            throw ValidationException::withMessages(['consent' => $labels['consent_required'] ?? 'Rozilik talab qilinadi.']);
        }
        $courseId = isset($data['course_id']) && Course::whereKey($data['course_id'])->where('published', true)->exists() ? (int) $data['course_id'] : null;
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($data['name'])));
        $comment = isset($data['comment']) ? trim(strip_tags($data['comment'])) : null;

        $r = $leads->capture(['name' => $name, 'phone' => $phone, 'course_id' => $courseId, 'comment' => $comment ?: null, 'consent' => (bool) ($data['consent'] ?? false)], 'site', array_filter([
            'ip' => $request->ip(), 'ua' => mb_substr((string) $request->userAgent(), 0, 255), 'utm' => array_filter($data['utm'] ?? []) ?: null,
        ]));
        Audit::log($r['created'] ? 'lead.public_create' : 'lead.public_duplicate', 'lead', $r['lead']->id, ['source' => 'site']);
        if ($r['created']) {
            $lead = $r['lead'];
            defer(fn () => $leads->notifyAdmins($lead));
        }

        return $ok;
    }
}
