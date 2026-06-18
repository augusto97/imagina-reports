<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

Route::get('/', static fn (): View => view('welcome'));

// Public report page (CLAUDE.md §10.7/§11.2): rendered by the shared BlockRenderer,
// served to clients and printed to PDF by Browsershot via this same URL.
Route::get('/reports/{token}', static fn (string $token): View => view('report', ['token' => $token]))
    ->name('report.public');

// Admin SPA (CLAUDE.md §11.1). Client-side routing handles everything under /admin.
Route::view('/admin/{any?}', 'admin')
    ->where('any', '.*')
    ->name('admin');

// Interactive client portal SPA (CLAUDE.md §11.2), opened via a signed public token.
Route::view('/portal/{any?}', 'portal')
    ->where('any', '.*')
    ->name('portal');
