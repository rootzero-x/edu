<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'TISU Edu API', 'version' => config('app.version')]));
