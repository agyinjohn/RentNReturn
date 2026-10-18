<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Sms\SmsSender;
use App\Verification\IdentityVerification;
use App\Verification\PhoneVerification;
use App\Verification\VerificationException;

class VerificationController extends Controller
{
    public function phoneForm(array $params = []): void
    {
        $user = Auth::user($this->config);
        $codeSentTo = $_SESSION['phone_code_sent_to'] ?? null;
        $error = $_SESSION['phone_error'] ?? null;
        $notice = $_SESSION['phone_notice'] ?? null;
        unset($_SESSION['phone_error'], $_SESSION['phone_notice']);
        if (isset($_GET['change'])) {
            $codeSentTo = null;
            unset($_SESSION['phone_code_sent_to']);
        }
        include $this->config['base_path'] . '/templates/verification/phone.php';
    }

    public function sendPhoneCode(array $params = []): void
    {
        $user = Auth::user($this->config);
        try {
            $masked = $this->phone()->sendCode((int) $user['id'], (string) ($_POST['phone'] ?? ''));
            $_SESSION['phone_code_sent_to'] = $masked;
            $_SESSION['phone_notice'] = "We've texted a 6-digit code to {$masked}.";
        } catch (VerificationException $e) {
            unset($_SESSION['phone_notice']);
            $_SESSION['phone_error'] = $e->getMessage();
        }
        $this->redirect('/verify/phone');
    }

    public function confirmPhone(array $params = []): void
    {
        $user = Auth::user($this->config);
        try {
            $masked = $this->phone()->confirmCode((int) $user['id'], (string) ($_POST['code'] ?? ''));
        } catch (VerificationException $e) {
            unset($_SESSION['phone_notice']);
            $_SESSION['phone_error'] = $e->getMessage();
            $this->redirect('/verify/phone');
            return;
        }
        unset($_SESSION['phone_code_sent_to']);
        $_SESSION['profile_success'] = "Mobile number {$masked} verified.";
        $this->redirect('/profile');
    }

    public function startIdentity(array $params = []): void
    {
        $user = Auth::user($this->config);
        try {
            $url = $this->identity()->start((int) $user['id'], $this->config['url'] . '/profile');
        } catch (VerificationException $e) {
            $_SESSION['profile_error'] = $e->getMessage();
            $this->redirect('/profile');
            return;
        }
        $this->redirect($url);
    }

    /** DEVELOPMENT ONLY: stand-in for a provider's hosted check page. */
    public function simulateForm(array $params = []): void
    {
        $user = Auth::user($this->config);
        $reference = (string) ($_GET['ref'] ?? '');
        if (!$this->simulatorAllowed() || !$this->identity()->isPendingFor((int) $user['id'], $reference)) {
            $this->notFound($user);
            return;
        }
        include $this->config['base_path'] . '/templates/verification/identity_simulate.php';
    }

    public function simulateSubmit(array $params = []): void
    {
        $user = Auth::user($this->config);
        $reference = (string) ($_POST['ref'] ?? '');
        if (!$this->simulatorAllowed() || !$this->identity()->isPendingFor((int) $user['id'], $reference)) {
            $this->notFound($user);
            return;
        }
        $passed = ($_POST['outcome'] ?? '') === 'pass';
        $this->identity()->recordResult('simulated', $reference, $passed ? 'verified' : 'failed', 'Simulated check (development)');
        $_SESSION[$passed ? 'profile_success' : 'profile_error'] = $passed ? 'Identity verified.' : 'The identity check did not pass. You can try again.';
        $this->redirect('/profile');
    }

    private function simulatorAllowed(): bool
    {
        return $this->config['env'] === 'development' && $this->config['identity']['provider'] === 'simulated';
    }

    private function notFound(?array $user): void
    {
        http_response_code(404);
        $currentUser = $user;
        include $this->config['base_path'] . '/templates/404.php';
    }

    private function phone(): PhoneVerification
    {
        return new PhoneVerification(
            Database::get($this->config),
            new SmsSender($this->config['sms']),
            $this->rateLimiter(),
            $this->config['verification'],
            $this->config['rate_limits']
        );
    }

    private function identity(): IdentityVerification
    {
        return new IdentityVerification(Database::get($this->config), IdentityVerification::providerFromConfig($this->config));
    }
}
