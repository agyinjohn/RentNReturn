<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
if (($config['env'] ?? 'development') === 'production') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

App\Auth::init($config);

$router = new App\Router($config);

// Guest
$router->get('/', 'App\Controllers\HomeController::index');
$router->get('/login', 'App\Controllers\AuthController::loginForm');
$router->post('/login', 'App\Controllers\AuthController::login');
$router->get('/signup', 'App\Controllers\AuthController::signupForm');
$router->post('/signup', 'App\Controllers\AuthController::signup');

// Auth required
$router->post('/logout', 'App\Controllers\AuthController::logout', true);
$router->get('/profile', 'App\Controllers\ProfileController::edit', true);
$router->post('/profile', 'App\Controllers\ProfileController::update', true);

// Listings (browse public; create/edit auth)
$router->get('/listings', 'App\Controllers\ListingController::index');
$router->get('/listings/create', 'App\Controllers\ListingController::createForm', true);
$router->post('/listings/create/describe', 'App\Controllers\ListingController::describeImage', true);
$router->get('/listings/create/review', 'App\Controllers\ListingController::reviewForm', true);
$router->post('/listings/create/review', 'App\Controllers\ListingController::saveFromReview', true);
$router->get('/listings/create/details', 'App\Controllers\ListingController::detailsForm', true);
$router->post('/listings/create', 'App\Controllers\ListingController::create', true);
$router->get('/listings/my', 'App\Controllers\ListingController::myListings', true);
$router->get('/listings/{slug}', 'App\Controllers\ListingController::show');
$router->post('/listings/{id}/publish', 'App\Controllers\ListingController::publish', true);
$router->post('/listings/{id}/unpublish', 'App\Controllers\ListingController::unpublish', true);

// AI description (auth)
$router->post('/api/listing/ai-describe', 'App\Controllers\Api\AiDescribeController::describe', true);

// Requests & bookings
$router->post('/listings/{id}/request', 'App\Controllers\RentalRequestController::create', true);
$router->get('/requests', 'App\Controllers\RentalRequestController::myRequests', true);
$router->get('/dashboard', 'App\Controllers\DashboardController::index', true);
$router->post('/requests/{id}/accept', 'App\Controllers\RentalRequestController::accept', true);
$router->post('/requests/{id}/decline', 'App\Controllers\RentalRequestController::decline', true);

$router->dispatch();
