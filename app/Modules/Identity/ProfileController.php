<?php

namespace MedCore\Modules\Identity;

use MedCore\Core\Auth;
use MedCore\Core\Config;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Core\Validator;
use MedCore\Repositories\UserRepository;
use MedCore\Services\AuditLogger;

/**
 * Own-profile management (any authenticated user).
 */
final class ProfileController
{
    public function show(): void
    {
        $user = Auth::user();
        Response::view('profile/show', [
            'title' => 'My profile',
            'user'  => $user,
            'roles' => UserRepository::rolesOf((int) $user['id']),
        ]);
    }

    public function update(Request $request): void
    {
        $user = Auth::user();
        $v = Validator::make($request->all(), [
            'name'  => 'required|min:2|max:120',
            'phone' => 'phone|max:20',
            'email' => 'required|email|max:190',
        ]);
        $errors = $v->errors();

        $existing = UserRepository::findByEmail((string) $request->input('email'));
        if ($existing !== null && (int) $existing['id'] !== (int) $user['id']) {
            $errors['email'][] = 'That email is already used by another account.';
        }
        if ($errors !== []) {
            Session::flashErrors($errors);
            Response::redirect('/profile');
            return;
        }

        $emailChanged = strtolower((string) $request->input('email')) !== $user['email'];
        UserRepository::update((int) $user['id'], [
            'name'  => trim((string) $request->input('name')),
            'phone' => trim((string) $request->input('phone', '')) ?: null,
            'email' => (string) $request->input('email'),
            // Changing email re-triggers verification if required.
            'email_verified_at' => $emailChanged && Config::get('app.auth.require_verification')
                ? null
                : $user['email_verified_at'],
        ]);
        AuditLogger::log((int) $user['id'], 'profile.updated', 'user', (int) $user['id'], ['fields' => ['name', 'email', 'phone']], $request);
        Session::flash('success', 'Profile updated.');
        Response::redirect('/profile');
    }

    public function changePassword(Request $request): void
    {
        $user = Auth::user();
        $min = (int) Config::get('app.auth.password_min_length', 10);
        $v = Validator::make($request->all(), [
            'current_password'      => 'required',
            'password'              => "required|min:$min|same:password_confirmation",
            'password_confirmation' => 'required',
        ]);
        if ($v->fails()) {
            Session::flashErrors($v->errors());
            Response::redirect('/profile');
            return;
        }
        if (!password_verify((string) $request->input('current_password'), (string) $user['password_hash'])) {
            Session::flashErrors(['current_password' => ['Your current password is incorrect.']]);
            Response::redirect('/profile');
            return;
        }
        UserRepository::updatePasswordHash((int) $user['id'], password_hash((string) $request->input('password'), PASSWORD_DEFAULT));
        AuditLogger::log((int) $user['id'], 'profile.password_changed', 'user', (int) $user['id'], null, $request);
        Session::flash('success', 'Password changed.');
        Response::redirect('/profile');
    }
}
