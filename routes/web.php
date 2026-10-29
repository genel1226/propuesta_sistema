<?php

use App\Http\Controllers\ImportDownloadController;
use App\Livewire\Imports\ImportIndex;
use App\Livewire\Imports\ImportShow;
use App\Livewire\Imports\ImportWizard;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('imports.create') : redirect()->route('login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'importar')->name('dashboard');

    Route::get('importar', ImportWizard::class)->name('imports.create');
    Route::get('importaciones', ImportIndex::class)->name('imports.index');
    Route::get('importaciones/{batch}', ImportShow::class)->name('imports.show');
    Route::get('importaciones/{batch}/mapeo', ImportWizard::class)->name('imports.edit');
    Route::get('importaciones/{batch}/descargar/{type}', ImportDownloadController::class)
        ->whereIn('type', ['result', 'clean'])
        ->name('imports.download');
});

require __DIR__.'/settings.php';
