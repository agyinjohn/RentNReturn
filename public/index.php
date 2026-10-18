<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';

App\Security::enforceHttps($config);
App\Security::sendHeaders($config);

App\Auth::init($config);

$router = new App\Router($config);

// Route flags: get/post(path, handler, auth required, verified email required)

// Guest
$router->get('/', 'App\Controllers\HomeController::index');
$router->get('/login', 'App\Controllers\AuthController::loginForm');
$router->post('/login', 'App\Controllers\AuthController::login');
$router->get('/signup', 'App\Controllers\AuthController::signupForm');
$router->post('/signup', 'App\Controllers\AuthController::signup');
$router->get('/forgot-password', 'App\Controllers\PasswordResetController::requestForm');
$router->post('/forgot-password', 'App\Controllers\PasswordResetController::sendLink');
$router->get('/reset-password', 'App\Controllers\PasswordResetController::resetForm');
$router->post('/reset-password', 'App\Controllers\PasswordResetController::reset');
$router->get('/verify-email/confirm', 'App\Controllers\EmailVerificationController::confirm');

// Auth required
$router->post('/logout', 'App\Controllers\AuthController::logout', true);
$router->get('/verify-email', 'App\Controllers\EmailVerificationController::notice', true);
$router->post('/verify-email/resend', 'App\Controllers\EmailVerificationController::resend', true);
$router->get('/profile', 'App\Controllers\ProfileController::edit', true);
$router->post('/profile', 'App\Controllers\ProfileController::update', true);

// Verification (mobile code, identity check). Public member pages show only display name, badge and history.
$router->get('/verify/phone', 'App\Controllers\VerificationController::phoneForm', true);
$router->post('/verify/phone', 'App\Controllers\VerificationController::sendPhoneCode', true);
$router->post('/verify/phone/confirm', 'App\Controllers\VerificationController::confirmPhone', true);
$router->post('/verify/identity', 'App\Controllers\VerificationController::startIdentity', true);
$router->get('/verify/identity/simulate', 'App\Controllers\VerificationController::simulateForm', true);
$router->post('/verify/identity/simulate', 'App\Controllers\VerificationController::simulateSubmit', true);
$router->get('/people/{id}', 'App\Controllers\PublicProfileController::show');

// Listings (browse public; creating/publishing needs a verified email)
$router->get('/listings', 'App\Controllers\ListingController::index');
$router->get('/listings/create', 'App\Controllers\ListingController::createForm', true, true);
$router->post('/listings/create/describe', 'App\Controllers\ListingController::describeImage', true, true);
$router->get('/listings/create/review', 'App\Controllers\ListingController::reviewForm', true, true);
$router->post('/listings/create/review', 'App\Controllers\ListingController::saveFromReview', true, true);
$router->get('/listings/create/details', 'App\Controllers\ListingController::detailsForm', true, true);
$router->get('/listings/create/draft-image', 'App\Controllers\ListingController::draftImage', true);
$router->post('/listings/create', 'App\Controllers\ListingController::create', true, true);
$router->get('/listings/my', 'App\Controllers\ListingController::myListings', true);
$router->get('/listings/{slug}', 'App\Controllers\ListingController::show');
$router->post('/listings/{id}/publish', 'App\Controllers\ListingController::publish', true, true);
$router->post('/listings/{id}/unpublish', 'App\Controllers\ListingController::unpublish', true);

// AI description
$router->post('/api/listing/ai-describe', 'App\Controllers\Api\AiDescribeController::describe', true, true);

// Requests & bookings (requesting and accepting need a verified email)
$router->post('/listings/{id}/request', 'App\Controllers\RentalRequestController::create', true, true);
$router->get('/requests', 'App\Controllers\RentalRequestController::myRequests', true);
$router->get('/dashboard', 'App\Controllers\DashboardController::index', true);
$router->post('/requests/{id}/accept', 'App\Controllers\RentalRequestController::accept', true, true);
$router->post('/requests/{id}/decline', 'App\Controllers\RentalRequestController::decline', true);
$router->get('/rentals/{id}', 'App\Controllers\RentalRequestController::show', true);
$router->post('/rentals/{id}/cancel-request', 'App\Controllers\RentalRequestController::cancelRequest', true);
$router->post('/rentals/{id}/status', 'App\Controllers\RentalRequestController::transition', true);

$router->dispatch();
