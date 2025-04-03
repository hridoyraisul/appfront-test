<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\ProductController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\AuthController;

Route::get('/', [ProductController::class, 'index']);

Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginPage'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
});

Route::middleware(['auth'])->prefix('admin')->group(function () {
    Route::resource('products', AdminProductController::class)->except(['update']);
    Route::post('products/{product}', [AdminProductController::class, 'update'])->name('products.update');
    Route::get('/logout', [AuthController::class, 'logout'])->name('logout');
});