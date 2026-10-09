<?php
/**
 * AuthController — registration (email/phone + OTP), login, password reset.
 *
 * Registration enforces the 18+ rule server-side. OTP codes are verified
 * before the account becomes usable. Failed logins are rate limited and
 * audited; accounts lock temporarily after repeated failures.
 */

declare(strict_types=1);

namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Core\Mailer;
use CodeAlpha\Core\RateLimiter;
use CodeAlpha\Core\Response;
use CodeAlpha\Core\Validator;
use CodeAlpha\Services\NotificationService;
use CodeAlpha\Services\OtpService;
use CodeAlpha\Services\RbacService;

final class AuthController extends Controller
{
    private const MIN_PASSWORD_LENGTH = 8;

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function showLogin(): never
    {
        if (Auth::check()) {
            Response::redirect(Auth::isStaff() ? '/staff' : '/community');
        }
        $this->view('auth/login', [], 'guest');
    }

    public function showRegister(): never
    {
        if (Auth::check()) {
            Response::redirect('/community');
        }
        if (!FeatureFlags::isEnabled('registration')) {
            $this->view('auth/registration-closed', [], 'guest');
        }
        $this->view('auth/register', [], 'guest');
    }

    public function showVerifyOtp(): never
    {
        if (Auth::check()) {
            Response::redirect('/community');
        }
        $pending = $_SESSION['__pending_otp'] ?? null;
        if (!$pending) {
            Response::redirect('/register');
        }
        $this->view('auth/verify-otp', ['pending' => $pending], 'guest');
    }

    public function showForgot(): never
    {
        $this->view('auth/forgot', [], 'guest');
    }

    public function showReset(): never
    {
        $token = $this->request->query('token', '');
        $this->view('auth/reset', ['token' => $token], 'guest');
    }

    // ------------------------------------------------------------------
    // Page form handlers (PRG)
    // ------------------------------------------------------------------

    public function login(): never
    {
        $identifier = $this->request->string('identifier');
        $password = (string) $this->request->input('password');
        $user = Auth::attempt($identifier, $password, $this->request);
        if (!$user) {
            stash_old_input(['identifier' => $identifier]);
            flash('error', 'Invalid credentials or the account is locked.');
            Response::redirect('/login');
        }
        if ($user['status'] === 'suspended') {
            flash('error', 'This account has been suspended. Please contact support.');
            Response::redirect('/login');
        }
        Auth::login($user);
        AuditLog::record((int) $user['id'], 'auth.login', 'user', (string) $user['id'], [], $this->request);
        $redirect = $this->request->query('redirect');
        if (is_string($redirect) && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            Response::redirect($redirect);
        }
        Response::redirect((int) $user['is_staff'] === 1 ? '/staff' : '/community');
    }

    public function register(): never
    {
        if (!FeatureFlags::isEnabled('registration')) {
            Response::error('Registration is currently disabled.', 404, ['code' => 'feature_disabled']);
        }
        $data = $this->request->all();
        $v = Validator::make($data, [
            'email'     => ['email'],
            'phone'     => ['phone'],
            'password'  => ['required', 'min:' . self::MIN_PASSWORD_LENGTH, 'max:72', 'confirmed'],
            'gender'    => ['required', 'in:male,female'],
            'birthdate' => ['required', 'date'],
            'display_name' => ['required', 'min:2', 'max:100'],
        ]);
        $hasEmail = !empty($data['email']);
        $hasPhone = !empty($data['phone']);
        if (!$hasEmail && !$hasPhone) {
            $v->fails(); // trigger
            flash('error', 'Provide an email address or a phone number.');
            stash_old_input($data);
            Response::redirect('/register');
        }
        if ($v->fails()) {
            flash('error', $v->firstError());
            stash_old_input($data);
            Response::redirect('/register');
        }

        // 18+ age requirement (server-side).
        $age = age_from_birthdate($data['birthdate']);
        if (FeatureFlags::isEnabled('age_check') && ($age === null || $age < 18)) {
            flash('error', 'You must be at least 18 years old to register.');
            stash_old_input($data);
            Response::redirect('/register');
        }

        if ($hasEmail && Database::scalar('SELECT COUNT(*) FROM users WHERE email = :e', ['e' => $data['email']])) {
            flash('error', 'That email address is already registered.');
            stash_old_input($data);
            Response::redirect('/register');
        }
        if ($hasPhone && Database::scalar('SELECT COUNT(*) FROM users WHERE phone = :p', ['p' => $data['phone']])) {
            flash('error', 'That phone number is already registered.');
            stash_old_input($data);
            Response::redirect('/register');
        }

        $limit = RateLimiter::hit('register:' . $this->request->ip(), 10, 3600);
        if (!$limit['allowed']) {
            flash('error', 'Too many registration attempts. Please try again later.');
            Response::redirect('/register');
        }

        $userId = (int) Database::insert('users', [
            'uuid'         => uuid(),
            'email'        => $hasEmail ? $data['email'] : null,
            'phone'        => $hasPhone ? $data['phone'] : null,
            'password_hash' => Auth::hashPassword($data['password']),
            'gender'       => $data['gender'],
            'birthdate'    => $data['birthdate'],
            'status'       => 'active',
            'is_staff'     => 0,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        Database::insert('profiles', [
            'user_id'           => $userId,
            'display_name'      => $data['display_name'],
            'verification_status' => 'unverified',
            'discoverable'      => 0,
            'privacy'           => json_encode(['show_distance' => true, 'show_online' => true, 'show_age' => true]),
            'notification_settings' => json_encode(['push' => true, 'email' => true]),
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        // Issue OTP on the available channel.
        $channel = $hasEmail ? 'email' : 'phone';
        $destination = $hasEmail ? $data['email'] : $data['phone'];
        OtpService::issue($userId, $channel, $destination, 'registration');

        AuditLog::record($userId, 'auth.register', 'user', (string) $userId, ['channel' => $channel], $this->request);

        $_SESSION['__pending_otp'] = [
            'user_id' => $userId, 'channel' => $channel, 'destination' => $destination,
        ];
        flash('success', 'We sent you a verification code.');
        Response::redirect('/verify-otp');
    }

    public function verifyOtp(): never
    {
        $pending = $_SESSION['__pending_otp'] ?? null;
        if (!$pending) {
            Response::redirect('/register');
        }
        $code = $this->request->string('code');
        $otp = OtpService::verify((int) $pending['user_id'], (string) $pending['destination'], 'registration', $code);
        if (!$otp) {
            flash('error', 'Invalid or expired code.');
            Response::redirect('/verify-otp');
        }
        $field = $pending['channel'] === 'email' ? 'email_verified_at' : 'phone_verified_at';
        Database::update('users', [$field => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $pending['user_id']]);
        unset($_SESSION['__pending_otp']);
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $pending['user_id']]);
        Auth::login($user);
        AuditLog::record((int) $user['id'], 'auth.otp_verified', 'user', (string) $user['id'], ['purpose' => 'registration'], $this->request);
        NotificationService::notify((int) $user['id'], 'welcome', 'Welcome to ' . config('app.name', 'CodeAlpha') . '!', 'Complete your profile and submit verification to enter discovery.');
        flash('success', 'Your account is verified. Complete your profile to get started.');
        Response::redirect('/profile');
    }

    public function forgot(): never
    {
        $identifier = $this->request->string('identifier');
        $user = Database::one('SELECT * FROM users WHERE (email = :i OR phone = :i2) AND deleted_at IS NULL', ['i' => $identifier, 'i2' => $identifier]);
        if ($user) {
            $limit = RateLimiter::hit('forgot:' . $this->request->ip(), 5, 3600);
            if ($limit['allowed']) {
                $channel = $user['email'] ? 'email' : 'phone';
                $destination = $channel === 'email' ? $user['email'] : $user['phone'];
                OtpService::issue((int) $user['id'], $channel, $destination, 'password_reset');
                $_SESSION['__reset_pending'] = ['user_id' => (int) $user['id'], 'channel' => $channel, 'destination' => $destination];
            }
        }
        // Always show the same message (do not reveal account existence).
        flash('success', 'If that account exists, we sent a reset code.');
        Response::redirect('/reset-password');
    }

    public function reset(): never
    {
        $pending = $_SESSION['__reset_pending'] ?? null;
        if (!$pending) {
            flash('error', 'Request a reset code first.');
            Response::redirect('/forgot-password');
        }
        $code = $this->request->string('code');
        $password = (string) $this->request->input('password');
        $confirm = (string) $this->request->input('password_confirmation');
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            flash('error', 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');
            Response::redirect('/reset-password');
        }
        if ($password !== $confirm) {
            flash('error', 'Passwords do not match.');
            Response::redirect('/reset-password');
        }
        $otp = OtpService::verify((int) $pending['user_id'], (string) $pending['destination'], 'password_reset', $code);
        if (!$otp) {
            flash('error', 'Invalid or expired code.');
            Response::redirect('/reset-password');
        }
        Database::update('users', [
            'password_hash' => Auth::hashPassword($password),
            'failed_logins' => 0,
            'locked_until'  => null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $pending['user_id']]);
        unset($_SESSION['__reset_pending']);
        AuditLog::record((int) $pending['user_id'], 'auth.password_reset', 'user', (string) $pending['user_id'], [], $this->request);
        flash('success', 'Your password has been reset. Please log in.');
        Response::redirect('/login');
    }

    public function logout(): never
    {
        AuditLog::record(Auth::id(), 'auth.logout', null, null, [], $this->request);
        Auth::logout();
        Response::redirect('/login');
    }

    // ------------------------------------------------------------------
    // JSON API
    // ------------------------------------------------------------------

    public function apiRegister(): never
    {
        if (!FeatureFlags::isEnabled('registration')) {
            $this->error('Registration is currently disabled.', 404, ['code' => 'feature_disabled', 'feature' => 'registration']);
        }
        $data = $this->request->all();
        $v = Validator::make($data, [
            'email'     => ['email'],
            'phone'     => ['phone'],
            'password'  => ['required', 'min:' . self::MIN_PASSWORD_LENGTH, 'max:72', 'confirmed'],
            'gender'    => ['required', 'in:male,female'],
            'birthdate' => ['required', 'date'],
            'display_name' => ['required', 'min:2', 'max:100'],
        ]);
        if ($v->fails()) {
            $this->error($v->firstError(), 422, ['code' => 'validation', 'errors' => $v->errors()]);
        }
        $hasEmail = !empty($data['email']);
        $hasPhone = !empty($data['phone']);
        if (!$hasEmail && !$hasPhone) {
            $this->error('Provide an email address or a phone number.', 422, ['code' => 'validation']);
        }
        $age = age_from_birthdate($data['birthdate']);
        if (FeatureFlags::isEnabled('age_check') && ($age === null || $age < 18)) {
            $this->error('You must be at least 18 years old to register.', 422, ['code' => 'underage']);
        }
        if ($hasEmail && Database::scalar('SELECT COUNT(*) FROM users WHERE email = :e', ['e' => $data['email']])) {
            $this->error('That email address is already registered.', 409, ['code' => 'email_taken']);
        }
        if ($hasPhone && Database::scalar('SELECT COUNT(*) FROM users WHERE phone = :p', ['p' => $data['phone']])) {
            $this->error('That phone number is already registered.', 409, ['code' => 'phone_taken']);
        }
        $limit = RateLimiter::hit('register:' . $this->request->ip(), 10, 3600);
        if (!$limit['allowed']) {
            RateLimiter::tooManyRequests($limit);
        }

        $userId = (int) Database::insert('users', [
            'uuid' => uuid(),
            'email' => $hasEmail ? $data['email'] : null,
            'phone' => $hasPhone ? $data['phone'] : null,
            'password_hash' => Auth::hashPassword($data['password']),
            'gender' => $data['gender'],
            'birthdate' => $data['birthdate'],
            'status' => 'active',
            'is_staff' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Database::insert('profiles', [
            'user_id' => $userId,
            'display_name' => $data['display_name'],
            'verification_status' => 'unverified',
            'discoverable' => 0,
            'privacy' => json_encode(['show_distance' => true, 'show_online' => true, 'show_age' => true]),
            'notification_settings' => json_encode(['push' => true, 'email' => true]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $channel = $hasEmail ? 'email' : 'phone';
        $destination = $hasEmail ? $data['email'] : $data['phone'];
        $otp = OtpService::issue($userId, $channel, $destination, 'registration');

        AuditLog::record($userId, 'auth.register', 'user', (string) $userId, ['channel' => $channel], $this->request);

        $response = [
            'user_id' => $userId,
            'channel' => $channel,
            'destination' => $channel === 'email'
                ? preg_replace('/(?<=.).(?=.*@)/', '*', $destination)
                : substr($destination, 0, 3) . '****' . substr($destination, -2),
            'otp_id' => $otp['otp_id'],
        ];
        if (config('app.debug', false) || config('app.env') === 'development') {
            // Development convenience: expose the code in the dev inbox only.
            $response['dev_code'] = $otp['code'];
        }
        $this->ok($response, 201);
    }

    public function apiVerifyOtp(): never
    {
        $userId = (int) $this->request->int('user_id');
        $destination = $this->request->string('destination');
        $code = $this->request->string('code');
        $purpose = $this->request->string('purpose', 'registration');
        $otp = OtpService::verify($userId ?: null, $destination, $purpose, $code);
        if (!$otp) {
            $this->error('Invalid or expired code.', 422, ['code' => 'invalid_code']);
        }
        $userId = (int) ($otp['user_id'] ?? $userId);
        $user = Database::one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if (!$user) {
            $this->error('User not found.', 404);
        }
        if ($purpose === 'registration') {
            $field = ($otp['channel'] ?? '') === 'email' ? 'email_verified_at' : 'phone_verified_at';
            Database::update('users', [$field => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
            Auth::login($user);
        }
        AuditLog::record($userId, 'auth.otp_verified', 'user', (string) $userId, ['purpose' => $purpose], $this->request);
        $this->ok(['verified' => true, 'user_id' => $userId]);
    }

    public function apiResendOtp(): never
    {
        $destination = $this->request->string('destination');
        $purpose = $this->request->string('purpose', 'registration');
        $userId = (int) $this->request->int('user_id');
        $limit = RateLimiter::hit('otp:' . $this->request->ip() . ':' . $destination, 5, 3600);
        if (!$limit['allowed']) {
            RateLimiter::tooManyRequests($limit);
        }
        if (!OtpService::canResend($destination, $purpose, 60)) {
            $this->error('Please wait before requesting another code.', 429, ['code' => 'rate_limited']);
        }
        $channel = str_contains($destination, '@') ? 'email' : 'phone';
        $otp = OtpService::issue($userId ?: null, $channel, $destination, $purpose);
        $response = ['sent' => true, 'channel' => $channel];
        if (config('app.debug', false) || config('app.env') === 'development') {
            $response['dev_code'] = $otp['code'];
        }
        $this->ok($response);
    }

    public function apiLogin(): never
    {
        $identifier = $this->request->string('identifier');
        $password = (string) $this->request->input('password');
        $limit = RateLimiter::hit('login:' . $this->request->ip(), 20, 900);
        if (!$limit['allowed']) {
            RateLimiter::tooManyRequests($limit);
        }
        $user = Auth::attempt($identifier, $password, $this->request);
        if (!$user) {
            $this->error('Invalid credentials.', 401, ['code' => 'invalid_credentials']);
        }
        if ($user['status'] === 'suspended') {
            $this->error('This account has been suspended. Please contact support.', 403, ['code' => 'suspended']);
        }
        Auth::login($user);
        AuditLog::record((int) $user['id'], 'auth.login', 'user', (string) $user['id'], [], $this->request);
        $this->ok(['user' => $this->userView($user)]);
    }

    public function apiLogout(): never
    {
        AuditLog::record(Auth::id(), 'auth.logout', null, null, [], $this->request);
        Auth::logout();
        $this->ok();
    }

    public function apiMe(): never
    {
        $user = Auth::user();
        if (!$user) {
            $this->error('Unauthenticated.', 401);
        }
        $this->ok([
            'user' => $this->userView($user),
            'permissions' => Auth::isStaff() ? array_keys(\CodeAlpha\Services\RbacService::permissionsFor((int) $user['id'])) : [],
            'features' => FeatureFlags::clientMap(),
        ]);
    }

    public function apiForgot(): never
    {
        $identifier = $this->request->string('identifier');
        $user = Database::one('SELECT * FROM users WHERE (email = :i OR phone = :i2) AND deleted_at IS NULL', ['i' => $identifier, 'i2' => $identifier]);
        if ($user) {
            $limit = RateLimiter::hit('forgot:' . $this->request->ip(), 5, 3600);
            if ($limit['allowed']) {
                $channel = $user['email'] ? 'email' : 'phone';
                $destination = $channel === 'email' ? $user['email'] : $user['phone'];
                OtpService::issue((int) $user['id'], $channel, $destination, 'password_reset');
            }
        }
        $this->ok(['sent' => true]); // Never reveal account existence.
    }

    public function apiReset(): never
    {
        $v = Validator::make($this->request->all(), [
            'destination' => ['required'],
            'code'        => ['required'],
            'password'    => ['required', 'min:' . self::MIN_PASSWORD_LENGTH, 'max:72', 'confirmed'],
        ]);
        if ($v->fails()) {
            $this->error($v->firstError(), 422, ['code' => 'validation', 'errors' => $v->errors()]);
        }
        $data = $this->request->all();
        $otp = OtpService::verify(null, (string) $data['destination'], 'password_reset', (string) $data['code']);
        if (!$otp || empty($otp['user_id'])) {
            $this->error('Invalid or expired code.', 422, ['code' => 'invalid_code']);
        }
        Database::update('users', [
            'password_hash' => Auth::hashPassword($data['password']),
            'failed_logins' => 0,
            'locked_until'  => null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $otp['user_id']]);
        AuditLog::record((int) $otp['user_id'], 'auth.password_reset', 'user', (string) $otp['user_id'], [], $this->request);
        $this->ok(['reset' => true]);
    }

    /** Public user view — never includes credentials or verification media. */
    private function userView(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'uuid' => $user['uuid'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'gender' => $user['gender'],
            'birthdate' => $user['birthdate'],
            'status' => $user['status'],
            'is_staff' => (bool) $user['is_staff'],
            'display_name' => $user['display_name'] ?? null,
            'verification_status' => $user['verification_status'] ?? 'unverified',
            'discoverable' => (bool) ($user['discoverable'] ?? false),
            'verified' => !empty($user['verified_at']),
            'email_verified' => !empty($user['email_verified_at']),
            'phone_verified' => !empty($user['phone_verified_at']),
            'created_at' => $user['created_at'],
        ];
    }
}
