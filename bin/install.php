<?php
/**
 * Installer — creates the database and seeds defaults.
 *
 * Usage:
 *   php bin/install.php              (uses config in config/config.php)
 *   php bin/install.php --refresh    (drop and recreate)
 *   php bin/install.php --seed-only  (only seed, do not recreate)
 *
 * When DB_DRIVER=sqlite (default for development without MySQL) the
 * authoritative MySQL schema is translated to SQLite on the fly.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
require $root . '/src/Core/helpers.php';

use CodeAlpha\Core\Database;
use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Core\Settings;
use CodeAlpha\Services\RbacService;

$refresh = in_array('--refresh', $argv ?? [], true);
$seedOnly = in_array('--seed-only', $argv ?? [], true);

function translate_mysql_to_sqlite(string $sql): string
{
    // Strip SET NAMES / FOREIGN_KEY_CHECKS, ENGINE=, CHARSET=, etc.
    $sql = preg_replace('/^\s*SET\s+[^;]+;\s*$/mi', '', $sql);
    // Remove ENGINE=..., DEFAULT CHARSET=..., COLLATE=... suffixes on CREATE TABLE lines
    $sql = preg_replace('/\)\s*ENGINE=[^;]+;/i', ');', $sql);
    // Replace ENUM('a','b') with TEXT (SQLite has no ENUM) — keep CHECK for validation via app
    $sql = preg_replace_callback('/\bENUM\s*\([^)]+\)/i', static fn($m) => 'TEXT', $sql);
    // BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY -> INTEGER PRIMARY KEY AUTOINCREMENT
    $sql = preg_replace('/\bBIGINT\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    // Remaining BIGINT UNSIGNED -> INTEGER
    $sql = preg_replace('/\bBIGINT\s+UNSIGNED\b/i', 'INTEGER', $sql);
    // INT UNSIGNED -> INTEGER
    $sql = preg_replace('/\bINT\s+UNSIGNED\b/i', 'INTEGER', $sql);
    // TINYINT(1) -> INTEGER
    $sql = preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i', 'INTEGER', $sql);
    // DECIMAL(12,2) -> REAL
    $sql = preg_replace('/\bDECIMAL\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'REAL', $sql);
    // DATETIME, TIMESTAMP(...) -> TEXT
    $sql = preg_replace('/\bDATETIME\b/i', 'TEXT', $sql);
    $sql = preg_replace('/\bTIMESTAMP\b/i', 'TEXT', $sql);
    // JSON -> TEXT
    $sql = preg_replace('/\bJSON\b/i', 'TEXT', $sql);
    // CHAR(36), CHAR(3), VARCHAR(...) -> TEXT
    $sql = preg_replace('/\b(CHAR|VARCHAR)\s*\([^)]+\)/i', 'TEXT', $sql);
    // TEXT(...) -> TEXT
    $sql = preg_replace('/\bTEXT\s*\([^)]+\)/i', 'TEXT', $sql);
    // Remove ON UPDATE CURRENT_TIMESTAMP clauses (MySQL only)
    $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $sql);
    // Remove DEFAULT CURRENT_TIMESTAMP where SQLite doesn't like it on TEXT — keep as DEFAULT (datetime('now'))
    // SQLite supports DEFAULT CURRENT_TIMESTAMP on TEXT, but to avoid issues we keep it; the app sets timestamps explicitly.
    // Remove CHARACTER SET / COLLATE fragments
    $sql = preg_replace('/\s+CHARACTER\s+SET\s+\w+/i', '', $sql);
    $sql = preg_replace('/\s+COLLATE\s+\w+/i', '', $sql);
    return $sql;
}

function exec_schema(string $schemaPath, bool $isSqlite): void
{
    $sql = file_get_contents($schemaPath);
    if ($sql === false) throw new RuntimeException("Cannot read schema: $schemaPath");
    if ($isSqlite) {
        $sql = translate_mysql_to_sqlite($sql);
        // Remove SQL comment lines (starting with --) so that a comment block
        // preceding a CREATE TABLE is not merged into the same statement.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    }
    // Split on semicolons that end statements (naive but works for this schema)
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $pdo = Database::pdo();
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        // Skip SET lines already removed
        if (preg_match('/^\s*SET\s+/i', $stmt)) continue;
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            // CREATE INDEX IF NOT EXISTS failures are non-fatal; log them
            if (str_contains(strtolower($e->getMessage()), 'already exists')) continue;
            // Foreign key errors during drop: ignore
            if (str_contains($e->getMessage(), 'foreign key')) continue;
            throw new RuntimeException("Schema error near: " . substr($stmt, 0, 180) . " — " . $e->getMessage(), 0, $e);
        }
    }
}

$isSqlite = Database::isSqlite();
$pdo = Database::pdo();

if ($refresh && !$seedOnly) {
    if ($isSqlite) {
        $path = (string) config('db.sqlite_path');
        if (is_file($path)) {
            echo "Removing SQLite file: $path\n";
            unlink($path);
            // Reconnect
            $ref = new ReflectionClass(Database::class);
            $prop = $ref->getProperty('pdo'); $prop->setAccessible(true); $prop->setValue(null, null);
            $pdo = Database::pdo();
        }
    } else {
        // MySQL: drop tables (disable FK checks, drop in reverse dependency order)
        echo "Dropping MySQL tables...\n";
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $tables = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

if (!$seedOnly) {
    echo "Creating schema (" . ($isSqlite ? 'SQLite (translated)' : 'MySQL') . ")...\n";
    exec_schema($root . '/database/schema.mysql.sql', $isSqlite);
    echo "Schema created.\n";
}

// Ensure storage directories
foreach (['storage/logs','storage/sessions','storage/cache','storage/protected/verifications','storage/protected/chat','public/uploads'] as $dir) {
    $full = $root . '/' . $dir;
    if (!is_dir($full)) mkdir($full, 0770, true);
}

// Seed feature flags
echo "Seeding feature flags...\n";
FeatureFlags::seedDefaults();

// Seed permissions catalog
echo "Seeding permissions...\n";
RbacService::seedPermissions();

// Seed roles
echo "Seeding roles...\n";
$roles = [
    ['name'=>'Super Admin','slug'=>'super_admin','dashboard'=>'super_admin','is_super'=>1,'is_system'=>1,'description'=>'Full system control'],
    ['name'=>'Administrator','slug'=>'admin','dashboard'=>'admin','is_super'=>0,'is_system'=>1,'description'=>'Users, profiles and moderation'],
    ['name'=>'Finance Officer','slug'=>'finance','dashboard'=>'finance','is_super'=>0,'is_system'=>1,'description'=>'Payments, plans and refunds'],
    ['name'=>'Support Agent','slug'=>'support','dashboard'=>'support','is_super'=>0,'is_system'=>1,'description'=>'Tickets, reports and appeals'],
    ['name'=>'Verification Officer','slug'=>'verification_officer','dashboard'=>'admin','is_super'=>0,'is_system'=>0,'description'=>'Verification reviews'],
    ['name'=>'Community Moderator','slug'=>'community_moderator','dashboard'=>'admin','is_super'=>0,'is_system'=>0,'description'=>'Community moderation'],
    ['name'=>'Campaign Manager','slug'=>'campaign_manager','dashboard'=>'super_admin','is_super'=>0,'is_system'=>0,'description'=>'Campaigns and adverts'],
];
foreach ($roles as $r) {
    $existing = Database::one('SELECT id FROM roles WHERE slug=:s',['s'=>$r['slug']]);
    if (!$existing) {
        Database::insert('roles', $r + ['created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
    }
}

// Attach all permissions to Super Admin
$superRole = Database::one("SELECT id FROM roles WHERE slug='super_admin'");
if ($superRole) {
    $allSlugs = array_keys(RbacService::catalog());
    RbacService::setRolePermissions((int)$superRole['id'], $allSlugs);
    echo "Super Admin role has all permissions (" . count($allSlugs) . ").\n";
}

// Finance role gets payments.view + refund etc.
$financeRole = Database::one("SELECT id FROM roles WHERE slug='finance'");
if ($financeRole) {
    $financePerms = ['dashboard.finance','payments.view','payments.refund','payments.reports','analytics.view'];
    RbacService::setRolePermissions((int)$financeRole['id'], $financePerms);
}

// Admin role
$adminRole = Database::one("SELECT id FROM roles WHERE slug='admin'");
if ($adminRole) {
    $adminPerms = ['dashboard.admin','users.view','users.suspend','verification.review','verification.approve','verification.reject','moderation.review','moderation.action','community.manage','support.reports'];
    RbacService::setRolePermissions((int)$adminRole['id'], $adminPerms);
}

// Support role
$supportRole = Database::one("SELECT id FROM roles WHERE slug='support'");
if ($supportRole) {
    $supportPerms = ['dashboard.support','support.tickets','support.appeals','support.reports'];
    RbacService::setRolePermissions((int)$supportRole['id'], $supportPerms);
}

// Verification officer
$voRole = Database::one("SELECT id FROM roles WHERE slug='verification_officer'");
if ($voRole) {
    RbacService::setRolePermissions((int)$voRole['id'], ['dashboard.admin','verification.review','verification.approve','verification.reject']);
}

// Community moderator
$cmRole = Database::one("SELECT id FROM roles WHERE slug='community_moderator'");
if ($cmRole) {
    RbacService::setRolePermissions((int)$cmRole['id'], ['dashboard.admin','moderation.review','moderation.action','community.manage']);
}

// Campaign manager
$campRole = Database::one("SELECT id FROM roles WHERE slug='campaign_manager'");
if ($campRole) {
    RbacService::setRolePermissions((int)$campRole['id'], ['dashboard.super_admin','campaigns.manage','advertisements.manage','analytics.view']);
}

// Seed subscription plans
echo "Seeding subscription plans...\n";
$existingPlans = (int)Database::scalar('SELECT COUNT(*) FROM subscription_plans');
if ($existingPlans === 0) {
    $plans = [
        ['name'=>'Basic','slug'=>'basic','description'=>'Essential features for getting started','price'=>1500,'currency'=>'NGN','interval'=>'month','features'=>json_encode(['Unlimited likes','Basic discovery','Community access']),'position'=>1],
        ['name'=>'Premium','slug'=>'premium','description'=>'Most popular — everything in Basic plus more visibility','price'=>3500,'currency'=>'NGN','interval'=>'month','features'=>json_encode(['Everything in Basic','See who liked you','Priority in discovery','Read receipts']),'position'=>2],
        ['name'=>'Premium Annual','slug'=>'premium_annual','description'=>'Premium billed annually — 2 months free','price'=>35000,'currency'=>'NGN','interval'=>'year','features'=>json_encode(['Everything in Premium','2 months free','Annual billing']),'position'=>3],
    ];
    foreach ($plans as $pl) {
        Database::insert('subscription_plans', $pl + ['is_active'=>1,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
    }
    echo "Seeded " . count($plans) . " plans.\n";
}

// Seed gateways (inactive by default, mock mode covers dev)
foreach (['paystack','flutterwave'] as $gwName) {
    if (!Database::one('SELECT id FROM payment_gateways WHERE name=:n',['n'=>$gwName])) {
        Database::insert('payment_gateways',['name'=>$gwName,'mode'=>'test','is_active'=>0,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
    }
}

// Seed demo users (only if no users exist yet)
$userCount = (int)Database::scalar('SELECT COUNT(*) FROM users');
if ($userCount === 0) {
    echo "Seeding demo accounts...\n";
    $now = date('Y-m-d H:i:s');
    // Super Admin
    $superId = (int)Database::insert('users', [
        'uuid'=>uuid(),'email'=>'super@codealpha.local','phone'=>null,'password_hash'=>password_hash('SuperAdmin123!', PASSWORD_BCRYPT, ['cost'=>10]),
        'gender'=>null,'birthdate'=>null,'status'=>'active','is_staff'=>1,'email_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now
    ]);
    if ($superRole) Database::insert('user_roles',['user_id'=>$superId,'role_id'=>$superRole['id'],'assigned_at'=>$now]);
    // Staff examples
    $adminId = (int)Database::insert('users', [
        'uuid'=>uuid(),'email'=>'admin@codealpha.local','phone'=>null,'password_hash'=>password_hash('Admin123!', PASSWORD_BCRYPT, ['cost'=>10]),
        'gender'=>null,'birthdate'=>null,'status'=>'active','is_staff'=>1,'email_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now
    ]);
    if ($adminRole) Database::insert('user_roles',['user_id'=>$adminId,'role_id'=>$adminRole['id'],'assigned_at'=>$now]);
    $financeId = (int)Database::insert('users', [
        'uuid'=>uuid(),'email'=>'finance@codealpha.local','phone'=>null,'password_hash'=>password_hash('Finance123!', PASSWORD_BCRYPT, ['cost'=>10]),
        'gender'=>null,'birthdate'=>null,'status'=>'active','is_staff'=>1,'email_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now
    ]);
    if ($financeRole) Database::insert('user_roles',['user_id'=>$financeId,'role_id'=>$financeRole['id'],'assigned_at'=>$now]);
    $supportId = (int)Database::insert('users', [
        'uuid'=>uuid(),'email'=>'support@codealpha.local','phone'=>null,'password_hash'=>password_hash('Support123!', PASSWORD_BCRYPT, ['cost'=>10]),
        'gender'=>null,'birthdate'=>null,'status'=>'active','is_staff'=>1,'email_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now
    ]);
    if ($supportRole) Database::insert('user_roles',['user_id'=>$supportId,'role_id'=>$supportRole['id'],'assigned_at'=>$now]);

    // Demo member accounts — opposite genders, approved, discoverable
    $demoMembers = [
        ['email'=>'ada@example.com','name'=>'Ada','gender'=>'female','birth'=>'1997-04-12','city'=>'Lagos','country'=>'Nigeria','bio'=>'Loves music, travel and good conversation. Looking for something real.','interests'=>['music','travel','books']],
        ['email'=>'chinedu@example.com','name'=>'Chinedu','gender'=>'male','birth'=>'1995-08-03','city'=>'Abuja','country'=>'Nigeria','bio'=>'Engineer, football fan, optimist. Let’s see where this goes.','interests'=>['football','tech','travel']],
        ['email'=>'sarah@example.com','name'=>'Sarah','gender'=>'female','birth'=>'1999-01-22','city'=>'Accra','country'=>'Ghana','bio'=>'Artist and dreamer. Coffee, galleries, long walks.','interests'=>['art','coffee','photography']],
        ['email'=>'emeka@example.com','name'=>'Emeka','gender'=>'male','birth'=>'1994-11-30','city'=>'Port Harcourt','country'=>'Nigeria','bio'=>'Entrepreneur, loves tech and beach days.','interests'=>['business','tech','beach']],
        ['email'=>'zainab@example.com','name'=>'Zainab','gender'=>'female','birth'=>'1996-06-15','city'=>'Kano','country'=>'Nigeria','bio'=>'Teacher, reader, kindness advocate.','interests'=>['books','teaching','music']],
        ['email'=>'tunde@example.com','name'=>'Tunde','gender'=>'male','birth'=>'1993-02-18','city'=>'Ibadan','country'=>'Nigeria','bio'=>'Fitness, finance, family. Looking for a partner in crime.','interests'=>['fitness','finance','family']],
    ];

    foreach ($demoMembers as $m) {
        $uid = (int)Database::insert('users', [
            'uuid'=>uuid(),'email'=>$m['email'],'phone'=>null,'password_hash'=>password_hash('Demo1234!', PASSWORD_BCRYPT, ['cost'=>10]),
            'gender'=>$m['gender'],'birthdate'=>$m['birth'],'status'=>'active','is_staff'=>0,'email_verified_at'=>$now,'phone_verified_at'=>null,'created_at'=>$now,'updated_at'=>$now
        ]);
        Database::insert('profiles', [
            'user_id'=>$uid,'display_name'=>$m['name'],'bio'=>$m['bio'],'interests'=>json_encode($m['interests']),
            'location_city'=>$m['city'],'location_country'=>$m['country'],'relationship_intention'=>'relationship',
            'verification_status'=>'approved','verified_at'=>$now,'discoverable'=>1,'hidden'=>0,'profile_score'=>80,
            'privacy'=>json_encode(['show_age'=>true,'show_distance'=>true,'show_online'=>true]),
            'notification_settings'=>json_encode(['push'=>true,'email'=>true]),
            'last_active_at'=>$now,'created_at'=>$now,'updated_at'=>$now
        ]);
    }
    echo "Seeded demo accounts (passwords: SuperAdmin123!, Admin123!, Finance123!, Support123!, Demo1234!)\n";
}

// Seed a sample community post
$postCount = (int)Database::scalar('SELECT COUNT(*) FROM community_posts');
if ($postCount === 0) {
    $ada = Database::one("SELECT id FROM users WHERE email='ada@example.com'");
    if ($ada) {
        Database::insert('community_posts', [
            'user_id'=>$ada['id'],'type'=>'text','body'=>"Welcome to CodeAlpha! 🎉\n\nThis is the community feed — share thoughts, photos and videos. Use #hashtags and @mentions to connect. Be kind and follow the guidelines. Paid content is always labelled as an Advertisement.",
            'visibility'=>'public','is_announcement'=>1,'is_pinned'=>1,'status'=>'published','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')
        ]);
    }
}

// Mark installed
file_put_contents($root . '/.installed', date('c'));
echo "Done. Installed marker written.\n";
if ($isSqlite) echo "SQLite database at: " . config('db.sqlite_path') . "\n";
else echo "MySQL database: " . config('db.name') . " @ " . config('db.host') . "\n";
