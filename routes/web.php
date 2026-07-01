<?php

declare(strict_types=1);

use App\Http\Controllers\EmbedController;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

Route::get('/', static fn (): View => view('welcome'));

// Public report page (CLAUDE.md §10.7/§11.2): rendered by the shared BlockRenderer,
// served to clients and printed to PDF by Browsershot via this same URL. A valid `print`
// token (server-only, passed by the PDF renderer) is injected so the print page can read
// password-protected reports; arbitrary visitors get no print token (Etapa D).
Route::get('/reports/{token}', static function (string $token, Request $request): View {
    $report = Report::query()->withoutGlobalScopes()->where('public_token', $token)->first();
    $print = $request->query('print');
    $printToken = $report !== null && is_string($print) && hash_equals($report->printToken(), $print) ? $print : '';

    return view('report', ['token' => $token, 'printToken' => $printToken]);
})->name('report.public');

// Private embedding (CLAUDE.md §11/Etapa D): the report inside an iframe, restricted
// to the definition's allowlisted domains via a CSP frame-ancestors header.
Route::get('/embed/{token}', [EmbedController::class, 'show'])->name('report.embed');

// Admin SPA (CLAUDE.md §11.1). Client-side routing handles everything under /admin.
Route::view('/admin/{any?}', 'admin')
    ->where('any', '.*')
    ->name('admin');

// Landing after a MercadoPago/PayPal checkout (the providers' back_url/return_url point
// here). Activation itself is async via the billing webhook; this page just reassures the
// payer and sends them back to the panel instead of a 404.
Route::view('/billing/return', 'billing-return')->name('billing.return');

// Interactive client portal SPA (CLAUDE.md §11.2), opened via a signed public token.
Route::get('/portal/{token}', static fn (string $token): View => view('portal', ['token' => $token]))
    ->name('portal');

// Live client dashboard SPA (CLAUDE.md §11.2/Etapa D), opened via a definition's
// dashboard token; explorable by date range, always current from the latest snapshots.
Route::get('/dashboard/{token}', static fn (string $token): View => view('dashboard', ['token' => $token]))
    ->name('dashboard');
