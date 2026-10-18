<?php
/** @var string $appName @var string $appUrl @var string $name */
$subject = 'You already have a ' . $appName . ' account';
$body = <<<TXT
Hi {$name},

Someone tried to sign up for {$appName} with this email address, but you already have an account.

Log in: {$appUrl}/login
Forgot your password? {$appUrl}/forgot-password

If this wasn't you, you can ignore this email.

— The {$appName} team
TXT;
