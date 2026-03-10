<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/hello-extension', function () {
        return view('ext-hello::index');
    })->name('ext.hello.index');
});
