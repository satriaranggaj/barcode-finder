<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ObjectSelectionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchFeedbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ProductController::class, 'index'])->name('products.index');
Route::post('/search', [ProductController::class, 'search'])->name('products.search');
Route::post('/object-selection', [ObjectSelectionController::class, 'propose'])->middleware('throttle:20,1')->name('object-selection.propose');
Route::post('/search-feedback', [SearchFeedbackController::class, 'store'])->middleware(['auth', 'throttle:20,1'])->name('search.feedback');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::prefix('admin')->name('admin.')->middleware('admin:admin,super_admin')->group(function (): void {
    Route::get('/', [ProductController::class, 'adminIndex'])->name('index');
    Route::get('/products/{product}', [ProductController::class, 'adminShow'])->name('products.show');
    Route::post('/products/{product}/photo', [ProductController::class, 'uploadPhoto'])->name('products.photo.store');
    Route::delete('/photos/{photo}', [ProductController::class, 'deletePhoto'])->name('photos.destroy');
    Route::get('/photos/{photo}/selection', [ObjectSelectionController::class, 'edit'])->name('photos.selection.edit');
    Route::get('/photos/{photo}/selection-image', [ObjectSelectionController::class, 'image'])->name('photos.selection.image');
    Route::put('/photos/{photo}/selection', [ObjectSelectionController::class, 'update'])->name('photos.selection.update');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('/object-selection', [ObjectSelectionController::class, 'propose'])->name('object-selection.propose');

    Route::middleware('admin:super_admin')->group(function (): void {
        Route::post('/search', [ProductController::class, 'search'])->name('search');
        Route::post('/import', [ProductController::class, 'importExcel'])->name('import');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/users/template', [AdminUserController::class, 'downloadTemplate'])->name('users.template');
        Route::post('/users/import', [AdminUserController::class, 'import'])->name('users.import');
        Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    });
});
