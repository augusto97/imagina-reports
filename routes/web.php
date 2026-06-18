<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

Route::get('/', static fn (): View => view('welcome'));

// Admin SPA (CLAUDE.md §11.1). Client-side routing handles everything under /admin.
Route::view('/admin/{any?}', 'admin')
    ->where('any', '.*')
    ->name('admin');

// Interactive client portal SPA (CLAUDE.md §11.2), opened via a signed public token.
Route::view('/portal/{any?}', 'portal')
    ->where('any', '.*')
    ->name('portal');
