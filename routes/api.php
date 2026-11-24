<?php
declare(strict_types=1);

use App\Http\Controllers\Api\CompanyController;
use App\Http\Middleware\RequireCompanyManage;
use Framework\Support\Facades\Route;

/**
 * REST routes.
 *
 * Loaded by Framework\Routing\RouteServiceProvider on `rest_api_init`.
 * Reached at /wp-json/{config('app.rest_namespace')}/...
 */

// Health check
Route::get('ping', static fn (): array => ['pong' => true])->name('ping');

// ------------------------------------------------------------------- companies

// Every endpoint is behind one capability check. The collection routes and the
// item routes are not in competition: WordPress dispatches a REST request on
// verb AND pattern, and the compiled patterns do not overlap - `companies`
// anchors to exactly that path, `companies/{id}` to exactly one segment after
// it - so `DELETE companies` (bulk) and `DELETE companies/{id}` are two
// distinct endpoints that both match their own requests and nothing else.
Route::group(['middleware' => [RequireCompanyManage::class]], function (): void {
    Route::get('companies', [CompanyController::class, 'index'])->name('api.companies.index');

    Route::post('companies', [CompanyController::class, 'store'])->name('api.companies.store');

    Route::delete('companies', [CompanyController::class, 'bulkDestroy'])->name('api.companies.bulk-destroy');

    Route::get('companies/{id}', [CompanyController::class, 'show'])->name('api.companies.show');

    Route::put('companies/{id}', [CompanyController::class, 'update'])->name('api.companies.update');

    Route::delete('companies/{id}', [CompanyController::class, 'destroy'])->name('api.companies.destroy');
});
