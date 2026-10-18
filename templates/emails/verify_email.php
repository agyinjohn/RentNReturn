<?php
/** @var string $appName @var string $appUrl @var string $name @var string $link */
$subject = 'Verify your email for ' . $appName;
$body = <<<TXT
Hi {$name},

Please confirm your email address to finish setting up your {$appName} account:

{$link}

This link expires in 24 hours. If you did not create an account, you can ignore this email.

— The {$appName} team
TXT;
