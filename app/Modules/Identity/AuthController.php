<?php

namespace MedCore\Modules\Identity;

use MedCore\Core\Auth;
use MedCore\Core\Config;
use MedCore\Core\Database;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Core\Validator;
use MedCore\Repositories\PatientRepository;
use MedCore\Repositories\RoleRepository;
use MedCore\Repositories\UserRepository;
use MedCore\Services\AuditLogger;
use MedCore\Services\NotificationService;

/**
 * Registration, login, logout, e-mail verification and password recovery.
 */
final class AuthController
{
    /* ----------------------------- LOGIN ----------------------------- */

    public function showLogin(): void
    {
        Response::view('auth/login', ['title' => 'Sign in'], 'auth');
    }

    public function login(Request $request): void
    {
        $v = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);
        if ($v->fails()) {
            self::failBack($v, '/login');
        }

        $error = Auth::attempt((string) $request->input('email'), (string) $request->input('password'), $request);
        if ($error !== null) {
            Session::flashInput($request->all());
            Session::flash('danger', $error);
            Response::redirect('/login');
            return;
        }

        $user = Auth::user();

        if (Config::get('app.auth.require_verification') && empty($user['email_verified_at'])) {
            Auth::logout($request);
            Session::flash('warning', 'Please verify your email address before signing in.');
            Response::redirect('/login');
            return;
        }

        Session::flash('success', 'Welcome back, ' . $user['name'] . '.');
        $intended = Session::get('_intended');
        Session::forget('_intended');
        Response::redirect(is_string($intended) ? $intended : '/dashboard');
    }

    public function logout(Request $request): void
    {
        Auth::logout($request);
        Session::start();
        Session::flash('success', 'You have been signed out.');
        Response::redirect('/login');
    }

    /* --------------------------- REGISTRATION ------------------------ */

    public function showRegister(): void
    {
        Response::view('auth/register', ['title' => 'Create your account'], 'auth');
    }

    /**
     * Self-service registration creates a PATIENT account:
     * a user record (patient role) + a linked MediID patient record + consent.
     */
    public function register(Request $request): void
    {
        $min = (int) Config::get('app.auth.password_min_length', 10);
        $v = Validator::make($request->all(), [
            'name'                  => 'required|min:2|max:120',
            'email'                 => 'required|email|max:190',
            'phone'                 => 'phone|max:20',
            'password'              => "required|min:$min|same:password_confirmation",
            'password_confirmation' => 'required',
            'date_of_birth'         => 'date',
            'sex'                   => 'in:female,male,other',
            'consent'               => 'required',
        ]);
        $errors = $v->errors();
        if (UserRepository::findByEmail((string) $request->input('email')) !== null) {
            $errors['email'][] = 'An account with this email already exists.';
        }
        if ($errors !== []) {
            Session::flashInput($request->all());
            Session::flashErrors($errors);
            Response::redirect('/register');
            return;
        }

        $verifyToken = bin2hex(random_bytes(32));
        $requireVerification = (bool) Config::get('app.auth.require_verification');

        [$firstName, $lastName] = self::splitName((string) $request->input('name'));

        $userId = Database::transaction(function () use ($request, $verifyToken, $requireVerification, $firstName, $lastName) {
            $userId = UserRepository::create([
                'name'               => trim((string) $request->input('name')),
                'email'              => (string) $request->input('email'),
                'phone'              => trim((string) $request->input('phone', '')) ?: null,
                'password_hash'      => password_hash((string) $request->input('password'), PASSWORD_DEFAULT),
                'status'             => 'active',
                'email_verified_at'  => $requireVerification ? null : date('Y-m-d H:i:s'),
                'email_verify_token' => $requireVerification ? hash('sha256', $verifyToken) : null,
            ]);
            $patientRole = RoleRepository::findBySlug('patient');
            if ($patientRole !== null) {
                UserRepository::addRole($userId, (int) $patientRole['id']);
            }
            // Every registered user gets a MediID patient record with consent.
            PatientRepository::create([
                'user_id'             => $userId,
                'first_name'          => $firstName,
                'last_name'           => $lastName,
                'date_of_birth'       => trim((string) $request->input('date_of_birth', '')) ?: null,
                'sex'                 => trim((string) $request->input('sex', '')) ?: null,
                'phone'               => trim((string) $request->input('phone', '')) ?: null,
                'consent_recorded_at' => date('Y-m-d H:i:s'),
                'created_by'          => $userId,
            ]);
            return $userId;
        });

        AuditLogger::log($userId, 'auth.register', 'user', $userId, ['self_registration' => true], $request);
        NotificationService::send($userId, 'Welcome to ' . Config::get('app.brand'), 'Your MedCore account and MediID health identity have been created.', 'success', '/dashboard');

        if ($requireVerification) {
            $link = '/verify?token=' . $verifyToken;
            if (Config::get('app.dev_show_links') && Config::get('app.debug')) {
                Session::flash('info', 'DEV MODE: verification link — ' . $link);
            }
            Session::flash('success', 'Account created. Check your email to verify your account.');
            Response::redirect('/login');
            return;
        }

        Session::flash('success', 'Account created. You can now sign in.');
        Response::redirect('/login');
    }

    /* ------------------------ EMAIL VERIFICATION --------------------- */

    public function verify(Request $request): void
    {
        $token = (string) $request->input('token', '');
        if ($token === '') {
            Response::abort(404);
        }
        $hash = hash('sha256', $token);
        $user = Database::first('SELECT * FROM users WHERE email_verify_token = ?', [$hash]);
        if ($user === null) {
            Response::view('auth/verify', ['title' => 'Verification', 'ok' => false], 'auth');
            return;
        }
        UserRepository::update((int) $user['id'], [
            'email_verified_at'  => date('Y-m-d H:i:s'),
            'email_verify_token' => null,
        ]);
        AuditLogger::log((int) $user['id'], 'auth.email_verified', 'user', (int) $user['id'], null, $request);
        Response::view('auth/verify', ['title' => 'Verification', 'ok' => true], 'auth');
    }

    /* ------------------------ PASSWORD RECOVERY ---------------------- */

    public function showForgot(): void
    {
        Response::view('auth/forgot', ['title' => 'Reset your password'], 'auth');
    }

    public function sendReset(Request $request): void
    {
        $v = Validator::make($request->all(), ['email' => 'required|email']);
        if ($v->fails()) {
            self::failBack($v, '/password/forgot');
        }

        $user = UserRepository::findByEmail((string) $request->input('email'));
        // Always show the same message — never reveal which emails exist.
        $generic = 'If an account exists for that email, a reset link has been sent.';

        if ($user !== null) {
            $token = bin2hex(random_bytes(32));
            Database::insert('password_resets', [
                'email'      => strtolower((string) $request->input('email')),
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                'created_ip' => $request->ip(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            AuditLogger::log((int) $user['id'], 'auth.reset_requested', 'user', (int) $user['id'], null, $request);

            if (Config::get('app.dev_show_links') && Config::get('app.debug')) {
                Session::flash('info', 'DEV MODE: reset link — /password/reset?token=' . $token . '&email=' . urlencode((string) $request->input('email')));
            }
        }
        Session::flash('success', $generic);
        Response::redirect('/password/forgot');
    }

    public function showReset(Request $request): void
    {
        Response::view('auth/reset', [
            'title' => 'Choose a new password',
            'token' => (string) $request->input('token', ''),
            'email' => (string) $request->input('email', ''),
        ], 'auth');
    }

    public function reset(Request $request): void
    {
        $min = (int) Config::get('app.auth.password_min_length', 10);
        $v = Validator::make($request->all(), [
            'email'                 => 'required|email',
            'token'                 => 'required',
            'password'              => "required|min:$min|same:password_confirmation",
            'password_confirmation' => 'required',
        ]);
        if ($v->fails()) {
            self::failBack($v, '/password/reset?token=' . urlencode((string) $request->input('token')) . '&email=' . urlencode((string) $request->input('email')));
        }

        $row = Database::first(
            'SELECT * FROM password_resets WHERE email = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1',
            [strtolower((string) $request->input('email'))]
        );
        $valid = $row !== null
            && hash_equals((string) $row['token_hash'], hash('sha256', (string) $request->input('token')))
            && strtotime((string) $row['expires_at']) >= time();

        if (!$valid) {
            Session::flash('danger', 'This reset link is invalid or has expired. Request a new one.');
            Response::redirect('/password/forgot');
            return;
        }

        $user = UserRepository::findByEmail((string) $request->input('email'));
        if ($user !== null) {
            Database::transaction(function () use ($user, $request, $row) {
                UserRepository::updatePasswordHash((int) $user['id'], password_hash((string) $request->input('password'), PASSWORD_DEFAULT));
                Database::execute('UPDATE password_resets SET used_at = ' . Database::nowExpr() . ' WHERE id = ?', [$row['id']]);
                // Invalidate every other outstanding reset link for this account.
                Database::execute('UPDATE password_resets SET used_at = ' . Database::nowExpr() . ' WHERE email = ? AND used_at IS NULL', [strtolower((string) $request->input('email'))]);
            });
            AuditLogger::log((int) $user['id'], 'auth.password_reset', 'user', (int) $user['id'], null, $request);
        }

        Session::flash('success', 'Password updated. You can sign in with your new password.');
        Response::redirect('/login');
    }

    /* ------------------------------ utils ---------------------------- */

    private static function failBack(Validator $v, string $to): void
    {
        Session::flashInput((new Request())->all());
        Session::flashErrors($v->errors());
        Response::redirect($to);
        throw new \MedCore\Core\HttpExit();
    }

    public static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $parts = explode(' ', $name, 2);
        return [$parts[0], $parts[1] ?? $parts[0]];
    }
}
