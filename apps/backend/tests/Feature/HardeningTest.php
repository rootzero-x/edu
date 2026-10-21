<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API: JSON siqish, ildiz manzilida sessiya cookie'lari yo'q, /health ichki tafsilotlarsiz. */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_json_is_gzipped_only_when_accepted(): void
    {
        $plain = $this->getJson('/v1/public/settings');
        $plain->assertOk();
        $this->assertNull($plain->headers->get('Content-Encoding'));

        $gz = $this->getJson('/v1/public/settings', ['Accept-Encoding' => 'gzip, br']);
        $gz->assertOk();
        $this->assertSame('gzip', $gz->headers->get('Content-Encoding'));
        $this->assertSame(json_decode($plain->getContent(), true), json_decode(gzdecode($gz->getContent()), true));
    }

    public function test_root_sets_no_cookies_and_health_has_no_version(): void
    {
        $r = $this->get('/')->assertOk()->assertJson(['name' => 'TISU Edu API']);
        $this->assertSame([], $r->headers->getCookies());
        $this->getJson('/health')->assertOk()->assertJsonMissingPath('version')->assertJsonMissingPath('scheduler.last_tick');
    }

    public function test_cleanup_removes_old_audit_logs_in_chunks_and_keeps_recent(): void
    {
        $old = now()->subDays(800);
        $rows = array_map(fn ($i) => ['action' => 'x.old', 'entity' => 'x', 'created_at' => $old], range(1, 12));
        \Illuminate\Support\Facades\DB::table('audit_logs')->insert($rows);
        \Illuminate\Support\Facades\DB::table('audit_logs')->insert(['action' => 'x.new', 'entity' => 'x', 'created_at' => now()]);
        $this->artisan('tisu:cleanup')->assertSuccessful();
        $this->assertSame(['x.new'], \Illuminate\Support\Facades\DB::table('audit_logs')->pluck('action')->all());
    }
}
