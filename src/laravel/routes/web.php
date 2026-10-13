<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\FlashcardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\StudyController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => Inertia::render('Login'))->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/password', fn () => Inertia::render('Password'));
    Route::post('/password', [AuthController::class, 'password']);
    Route::get('/', [StudyController::class, 'home'])->name('dashboard');
    Route::get('/practice', [StudyController::class, 'practice']);
    Route::post('/attempts', [StudyController::class, 'start'])->middleware('throttle:30,1');
    Route::get('/attempts/{attempt}', [StudyController::class, 'show']);
    Route::post('/attempts/{attempt}/answer', [StudyController::class, 'answer']);
    Route::post('/attempts/{attempt}/finish', [StudyController::class, 'finish']);
    Route::post('/attempts/{attempt}/heartbeat', [StudyController::class, 'heartbeat']);
    Route::post('/assignments/{assignment}/start', [StudyController::class, 'assigned']);
    Route::get('/library', [StudyController::class, 'library']);
    Route::post('/bookmarks/{question}', [StudyController::class, 'bookmark']);
    Route::get('/progress', [StudyController::class, 'progress']);
    Route::get('/flashcards', [FlashcardController::class, 'index']);
    Route::post('/flashcards/preview', [FlashcardController::class, 'preview']);
    Route::post('/flashcards/decks', [FlashcardController::class, 'create']);
    Route::get('/flashcards/decks/{deck}', [FlashcardController::class, 'show']);
    Route::post('/flashcards/decks/{deck}/answer', [FlashcardController::class, 'answer']);
    Route::post('/flashcards/decks/{deck}/rate', [FlashcardController::class, 'rate']);
    Route::get('/exams', [ExamController::class, 'index']);
    Route::post('/exams', [ExamController::class, 'store']);
    Route::post('/exams/{exam}/export', [ExamController::class, 'export'])->middleware('throttle:10,1');
    Route::get('/exports/{export}/{kind}', [ExamController::class, 'download']);
    Route::get('/import', [ImportController::class, 'index']);
    Route::post('/import', [ImportController::class, 'store'])->middleware('throttle:10,1');
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/', [AdminController::class, 'index']);
        Route::post('/users', [AdminController::class, 'createUser']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
        Route::get('/questions', [AdminController::class, 'questions']);
        Route::patch('/questions/{question}', [AdminController::class, 'edit']);
        Route::post('/import', [AdminController::class, 'import']);
        Route::post('/exams/{exam}/assign', [AdminController::class, 'assign']);
        Route::patch('/assignments/{assignment}', [AdminController::class, 'release']);
        Route::get('/analytics.csv', [AdminController::class, 'csv']);
    });
});
