<?php

declare(strict_types=1);

/**
 * MedCore lightweight test runner (no external dependencies).
 *
 *   php tests/run.php
 *
 * Covers the pure-logic core: Validator, Csrf, Router/Route matching,
 * Auth password hashing, patient-code/reference formatting and the
 * appointment state machine. HTTP-level checks live in
 * tests/security-checklist.md for manual verification.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use MedCore\Core\Config;
use MedCore\Core\Csrf;
use MedCore\Core\Route;
use MedCore\Core\Session;
use MedCore\Core\Validator;
use MedCore\Repositories\AppointmentRepository;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "  PASS  {$name}\n");
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDOUT, "  FAIL  {$name} — {$e->getMessage()}\n");
    }
}

function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

/* ------------------------------- Validator ---------------------------- */
test('Validator: required + email', function () {
    $v = Validator::make(['email' => 'not-an-email', 'name' => ''], ['email' => 'required|email', 'name' => 'required']);
    ok($v->fails());
    ok($v->first('email') !== null && $v->first('name') !== null);
});

test('Validator: passes valid input', function () {
    $v = Validator::make(
        ['email' => 'a@b.test', 'name' => 'Ada Lovelace', 'age' => '36'],
        ['email' => 'required|email', 'name' => 'required|min:2', 'age' => 'integer']
    );
    ok(!$v->fails(), 'expected no errors: ' . json_encode($v->errors()));
});

test('Validator: same + min', function () {
    $v = Validator::make(['p1' => 'short', 'p2' => 'shorty'], ['p1' => 'required|min:10|same:p2']);
    ok($v->fails());
});

test('Validator: in-list enforced', function () {
    $v = Validator::make(['sex' => 'alien'], ['sex' => 'in:female,male,other']);
    ok($v->fails());
    $v2 = Validator::make(['sex' => 'female'], ['sex' => 'in:female,male,other']);
    ok(!$v2->fails());
});

/* --------------------------------- Csrf ------------------------------- */
test('Csrf: token is stable per session and verifies via field', function () {
    @session_start();
    $t1 = Csrf::token();
    $t2 = Csrf::token();
    ok($t1 === $t2 && strlen($t1) === 64);
    $_POST['_token'] = $t1;
    $req = new MedCore\Core\Request();
    ok(Csrf::verify($req), 'field token should verify');
    unset($_POST['_token']);
});

test('Csrf: rejects wrong/empty tokens', function () {
    $req = new MedCore\Core\Request();
    ok(!Csrf::verify($req), 'empty should fail');
    $_POST['_token'] = str_repeat('a', 64);
    ok(!Csrf::verify(new MedCore\Core\Request()), 'wrong should fail');
    unset($_POST['_token']);
});

/* ------------------------------ Router/Route -------------------------- */
test('Route: matches static and parameterised URIs', function () {
    $r = new Route('GET', '/patients/{id}', ['X', 'y']);
    ok($r->matches('/patients') === false);
    $params = $r->matches('/patients/42');
    ok($params !== false && $params['id'] === '42');
    ok($r->matches('/patients/42/edit') === false);
});

test('Route: root route matches only root', function () {
    $r = new Route('GET', '/', ['X', 'y']);
    ok($r->matches('/') !== false);
    ok($r->matches('/login') === false);
});

/* ---------------------------- password policy ------------------------- */
test('Auth: password_hash/verify roundtrip + rehash detection', function () {
    $pw = 'MedCore#2026';
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    ok(password_verify($pw, $hash));
    ok(!password_verify('wrong', $hash));
    ok(password_needs_rehash($hash, PASSWORD_DEFAULT) === false);
});

test('Config: minimum password length is enforced by policy', function () {
    ok((int) Config::get('app.auth.password_min_length') >= 10);
});

/* ------------------------- appointment state machine ------------------ */
test('Appointments: statuses are a closed set', function () {
    ok(in_array('booked', AppointmentRepository::STATUSES, true));
    ok(!in_array('hacked', AppointmentRepository::STATUSES, true));
});

/* --------------------------- identifier format ------------------------ */
test('MediID code format', function () {
    $code = MedCore\Repositories\PatientRepository::generateCode(42);
    ok(preg_match('/^MCB-\d{4}-00042$/', $code) === 1, "bad code: {$code}");
});

test('Output escaping helper blocks XSS', function () {
    $out = e('<script>alert(1)</script>');
    ok(strpos($out, '<script>') === false);
});

fwrite(STDOUT, "\n{$passed} passed, {$failed} failed\n");
exit($failed > 0 ? 1 : 0);
