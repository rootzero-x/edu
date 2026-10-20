<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function log(string $action, string $entity, ?int $entityId = null, array $data = []): void
    {
        $req = request();
        DB::table('audit_logs')->insert([
            'actor_id' => $req->user()?->id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'ip' => $req->ip(),
            'created_at' => now(),
        ]);
    }
}
