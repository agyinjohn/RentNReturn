<?php
/** @var string $appName @var string $appUrl @var string $name @var string $link */
$subject = 'Reset your ' . $appName . ' password';
$body = <<<TXT
Hi {$name},

We received a request to reset your {$appName} password. Use this link to choose a new one:

{$link}

This link expires in 1 hour and can only be used once. If you did not ask to reset your password,
you can ignore this email — your password will not change.

— The {$appName} team
TXT;
