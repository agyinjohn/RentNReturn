<?php
/** @var string $appName @var string $appUrl @var string $name */
$subject = 'Your ' . $appName . ' password was changed';
$body = <<<TXT
Hi {$name},

The password for your {$appName} account was just changed, and any other signed-in devices were logged out.

If you did not do this, reset your password now: {$appUrl}/forgot-password

— The {$appName} team
TXT;
