<?php

use App\Domain\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// Birinchi super-admin: php artisan tisu:create-admin +998901234567 Ism
// Parol interaktiv so'raladi va hech qayerga yozilmaydi.
Artisan::command('tisu:create-admin {phone} {first_name}', function (string $phone, string $first_name) {
    $password = $this->secret('Parol (kamida 10 belgi)');
    if (strlen((string) $password) < 10) {
        $this->error('Parol juda qisqa.');

        return 1;
    }
    $user = User::updateOrCreate(['phone' => $phone], [
        'first_name' => $first_name, 'password' => $password, 'role' => Role::SuperAdmin, 'active' => true,
    ]);
    $this->info("Super-admin tayyor: #{$user->id} {$user->phone}");
})->purpose('Super-admin yaratish');
