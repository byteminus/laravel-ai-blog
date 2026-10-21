<?php

use ByteMinus\AiBlog\Http\Controllers\DeliveryController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('ai-blog.delivery.prefix', 'api/blog/v1'))
    ->middleware(config('ai-blog.delivery.middleware', ['api', 'throttle:60,1']))
    ->name('ai-blog.delivery.')
    ->group(function () {
        Route::get('/posts', [DeliveryController::class, 'postsIndex'])->name('posts.index');
        Route::get('/posts/by-slug/{slug}', [DeliveryController::class, 'postsBySlug'])->name('posts.by-slug');
        Route::get('/posts/{id}', [DeliveryController::class, 'postsShow'])->whereNumber('id')->name('posts.show');
        Route::get('/categories', [DeliveryController::class, 'categories'])->name('categories');
    });
