<?php

use App\Http\Controllers\FactoryController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hangar')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('{view}', FactoryController::class)
        ->whereIn('view', FactoryController::VIEWS)
        ->name('factory');

});

require __DIR__.'/settings.php';
