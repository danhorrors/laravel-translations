<?php

use Illuminate\Support\Facades\Route;
use danhorrors\Translations\Http\Controllers\TranslationEditorController;

Route::group([
    'namespace' => 'danhorrors\Translations\Http\Controllers',
    'middleware'  => ['web', 'auth', 'admin'],  // Ensure only authenticated admin users can access
    'prefix'      => 'translations/editor'
], function () {
    Route::get('/', [TranslationEditorController::class, 'index'])->name('translations.editor.index');
    Route::post('/', [TranslationEditorController::class, 'update'])->name('translations.editor.update');
});
