#!/usr/bin/env php
<?php
/**
 * Post-migration verification and fix script for MAS prod-to-dev clone.
 *
 * Verifies and fixes settings that SQL migration scripts handle poorly
 * (especially serialized PHP data, where REPLACE() corrupts s:N: length
 * prefixes). Run after mas_mas_*_to_dev.sql scripts.
 *
 * Usage:
 *   set -a && source /home/brian/.config/development/databases.env && set +a
 *   php post-migration-verify.php
 *
 * Exit codes: 0 = all OK (with or without fixes), 1 = unfixable error
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Recompute s:N:"..." length prefixes in a PHP-serialized string.
 * Repairs the common REPLACE() corruption where the prefix doesn't match
 * the new string length (e.g. s:18:"info@masdemo.localhost" — 22 chars).
 */
function repair_lengths(string $serialized): string
{
    return preg_replace_callback(
        '/s:\d+:"(.*?)";/s',
        fn($m) => 's:' . strlen($m[1]) . ':"' . $m[1] . '";',
        $serialized
    );
}

/**
 * Try plain unserialize; if it fails, try again after length-repair.
 * Returns [$value, $wasRepaired] or [false, false] if unrecoverable.
 */
function unserialize_with_repair(string $raw): array
{
    $r = @unserialize($raw);
    if ($r !== false || $raw === 'b:0;') {
        return [$r, false];
    }
    $repaired = repair_lengths($raw);
    $r = @unserialize($repaired);
    if ($r !== false || $repaired === 'b:0;') {
        return [$r, true];
    }
    return [false, false];
}

// ---------------------------------------------------------------------------
// Load credentials from environment
// ---------------------------------------------------------------------------
$wpDb   = getenv('MASDEMO_WP_DB_NAME') ?: 'mas_dev';
$civiDb = getenv('MASDEMO_CIVI_DB_NAME') ?: 'mas_dev_civi';
$dbUser = getenv('MYSQL_ROOT_USER') ?: 'brian';
$dbPass = getenv('MYSQL_ROOT_PASSWORD') ?: '';

$pdo = new PDO("mysql:host=localhost", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$issues = [];
$fixes  = [];
$warns  = [];

// ---------------------------------------------------------------------------
// 1. WP Mail SMTP — must be mailer=smtp, host=localhost, port=1025, no TLS
//    Also handles s:N: length corruption from REPLACE on email addresses.
// ---------------------------------------------------------------------------
$raw = $pdo->query("SELECT option_value FROM {$wpDb}.wp_options WHERE option_name = 'wp_mail_smtp'")->fetchColumn();

if ($raw === false) {
    $warns[] = "wp_mail_smtp option not found — is the plugin installed?";
} else {
    [$smtp, $wasRepaired] = unserialize_with_repair($raw);
    if ($smtp === false) {
        $issues[] = "wp_mail_smtp option is corrupted (unserialize failed even after length-repair)";
    } else {
        $needsFix = $wasRepaired;
        if ($wasRepaired) {
            $fixes[] = "wp_mail_smtp serialization length prefixes repaired";
        }

        if (($smtp['mail']['mailer'] ?? '') !== 'smtp') {
            $smtp['mail']['mailer'] = 'smtp';
            $needsFix = true;
        }
        if (($smtp['smtp']['host'] ?? '') !== 'localhost') {
            $smtp['smtp']['host'] = 'localhost';
            $needsFix = true;
        }
        if (($smtp['smtp']['port'] ?? '') != 1025) {
            $smtp['smtp']['port'] = 1025;
            $needsFix = true;
        }
        if (($smtp['smtp']['encryption'] ?? '') !== 'none') {
            $smtp['smtp']['encryption'] = 'none';
            $needsFix = true;
        }
        if (!empty($smtp['smtp']['autotls'])) {
            $smtp['smtp']['autotls'] = false;
            $needsFix = true;
        }
        if (!empty($smtp['smtp']['auth'])) {
            $smtp['smtp']['auth'] = false;
            $needsFix = true;
        }

        if ($needsFix) {
            $upd = $pdo->prepare("UPDATE {$wpDb}.wp_options SET option_value = ? WHERE option_name = 'wp_mail_smtp'");
            $upd->execute([serialize($smtp)]);
            if (!$wasRepaired) {
                $fixes[] = "WP Mail SMTP settings corrected (smtp, localhost:1025, no TLS)";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 2. CiviCRM mailing_backend — must be localhost:1025, no auth
// ---------------------------------------------------------------------------
$raw = $pdo->query("SELECT value FROM {$civiDb}.civicrm_setting WHERE name = 'mailing_backend'")->fetchColumn();

if ($raw !== false) {
    [$backend, $wasRepaired] = unserialize_with_repair($raw);
    if ($backend === false) {
        // Unrecoverable — rewrite from scratch
        $backend = [
            'outBound_option' => '0',
            'qfKey'           => 'development_key',
            'entryURL'        => 'http://masdemo.localhost/wp-admin/admin.php?page=CiviCRM',
            'sendmail_path'   => '',
            'sendmail_args'   => '',
            'smtpServer'      => 'localhost',
            'smtpPort'        => '1025',
            'smtpAuth'        => '0',
            'smtpUsername'    => '',
            'smtpPassword'    => '',
        ];
        $upd = $pdo->prepare("UPDATE {$civiDb}.civicrm_setting SET value = ? WHERE name = 'mailing_backend'");
        $upd->execute([serialize($backend)]);
        $fixes[] = "CiviCRM mailing_backend rewritten from scratch";
    } else {
        $needsFix = $wasRepaired;
        if ($wasRepaired) {
            $fixes[] = "CiviCRM mailing_backend serialization length prefixes repaired";
        }
        if (($backend['smtpServer'] ?? '') !== 'localhost') {
            $backend['smtpServer'] = 'localhost';
            $needsFix = true;
        }
        if (($backend['smtpPort'] ?? '') !== '1025') {
            $backend['smtpPort'] = '1025';
            $needsFix = true;
        }
        if (($backend['smtpAuth'] ?? '') !== '0') {
            $backend['smtpAuth'] = '0';
            $backend['smtpUsername'] = '';
            $backend['smtpPassword'] = '';
            $needsFix = true;
        }
        if ($needsFix) {
            $upd = $pdo->prepare("UPDATE {$civiDb}.civicrm_setting SET value = ? WHERE name = 'mailing_backend'");
            $upd->execute([serialize($backend)]);
            if (!$wasRepaired) {
                $fixes[] = "CiviCRM mailing_backend corrected (localhost:1025, no auth)";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 3. WordPress URLs — siteurl and home (informational; constants override)
// ---------------------------------------------------------------------------
foreach (['siteurl', 'home'] as $opt) {
    $val = $pdo->query("SELECT option_value FROM {$wpDb}.wp_options WHERE option_name = '{$opt}'")->fetchColumn();
    if (stripos($val, 'masadvise.org') !== false) {
        $warns[] = "{$opt} still points to production: {$val}";
    }
}

// ---------------------------------------------------------------------------
// 4. CiviCRM environment and debug
// ---------------------------------------------------------------------------
$stmt = $pdo->query("SELECT name, value FROM {$civiDb}.civicrm_setting WHERE name IN ('environment', 'debug_enabled', 'backtrace') ORDER BY id DESC");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!isset($settings[$row['name']])) {
        $settings[$row['name']] = @unserialize($row['value']);
    }
}
if (($settings['environment'] ?? '') !== 'Development') {
    $warns[] = "CiviCRM environment is '{$settings['environment']}', expected 'Development'";
}
if (($settings['debug_enabled'] ?? 0) != 1) {
    $warns[] = "CiviCRM debug_enabled is off, expected on";
}

// ---------------------------------------------------------------------------
// 5. Search engine indexing
// ---------------------------------------------------------------------------
if ($pdo->query("SELECT option_value FROM {$wpDb}.wp_options WHERE option_name = 'blog_public'")->fetchColumn() != '0') {
    $warns[] = "Search engine indexing is enabled (blog_public != 0)";
}

// ---------------------------------------------------------------------------
// 6. RECAPTCHA keys — should be dev keys
// ---------------------------------------------------------------------------
$key = $pdo->query("SELECT option_value FROM {$wpDb}.wp_options WHERE option_name = 'elementor_pro_recaptcha_v3_site_key'")->fetchColumn();
if ($key && !str_starts_with($key, '6LfvD_wq')) {
    $warns[] = "RECAPTCHA site key doesn't match dev key: {$key}";
}

// ---------------------------------------------------------------------------
// 7. active_plugins — repair gap corruption + apply dev plugin policy
//    Migration's REPLACE removes wordfence/w3-total-cache/unlimited-elements
//    entries inline, leaving the array declared a:23 with only 21 entries
//    at non-contiguous indices. PHP unserialize then fails.
// ---------------------------------------------------------------------------
$raw = $pdo->query("SELECT option_value FROM {$wpDb}.wp_options WHERE option_name = 'active_plugins'")->fetchColumn();

if ($raw !== false) {
    [$plugins, $wasRepaired] = unserialize_with_repair($raw);
    if (!is_array($plugins)) {
        // Direct unserialize and length-repair both failed — fall back to regex extract
        preg_match_all('/s:\d+:"([^"]+\.php)"/', $raw, $m);
        $plugins = $m[1] ?? [];
        if (!empty($plugins)) {
            $wasRepaired = true;
            $fixes[] = "active_plugins recovered via regex extraction (" . count($plugins) . " entries)";
        } else {
            $issues[] = "active_plugins is unrecoverable";
        }
    }

    if (is_array($plugins)) {
        // Always reindex contiguously (cheap; defends against gap corruption)
        $plugins = array_values($plugins);

        // Dev plugin policy: remove these (they're in active_plugins on prod
        // but should be inactive on dev — see "Expected Differences" in SKILL.md)
        $devRemove = [
            'better-wp-security/better-wp-security.php',
            'wordfence/wordfence.php',
            'w3-total-cache/w3-total-cache.php',
            'unlimited-elements-for-elementor/unlimited-elements-for-elementor.php',
        ];
        // Dev plugin policy: ensure these are active (inactive on prod, active on dev)
        $devEnsure = [
            'wp-mail-smtp/wp_mail_smtp.php',
        ];

        $original = $plugins;
        $plugins  = array_values(array_diff($plugins, $devRemove));
        foreach ($devEnsure as $p) {
            if (!in_array($p, $plugins, true)) {
                $plugins[] = $p;
            }
        }

        $needsWrite = $wasRepaired || $plugins !== $original;
        if ($needsWrite) {
            $upd = $pdo->prepare("UPDATE {$wpDb}.wp_options SET option_value = ? WHERE option_name = 'active_plugins'");
            $upd->execute([serialize($plugins)]);
            $deltaRemoved = array_diff($original, $plugins);
            $deltaAdded   = array_diff($plugins, $original);
            $note = [];
            if ($deltaRemoved) $note[] = "removed: " . implode(', ', array_map(fn($p) => basename(dirname($p)), $deltaRemoved));
            if ($deltaAdded)   $note[] = "added: " . implode(', ', array_map(fn($p) => basename(dirname($p)), $deltaAdded));
            $fixes[] = "active_plugins reindexed + policy applied (" . count($plugins) . " active" . ($note ? "; " . implode('; ', $note) : "") . ")";
        }
    }
}

// ---------------------------------------------------------------------------
// 8. Elementor kit settings — repair s:N: length corruption.
//    Affects _elementor_page_settings for the active Kit (typically post_id
//    5288 on MAS). Without this, Elementor's global colors/typography don't
//    apply and forms (e.g. newsletter signup) render with invisible text.
//    Also purges stale _elementor_css so it regenerates on next page load.
// ---------------------------------------------------------------------------
$kitRows = $pdo->query(
    "SELECT pm.post_id, pm.meta_value
       FROM {$wpDb}.wp_postmeta pm
       JOIN {$wpDb}.wp_postmeta tt ON tt.post_id = pm.post_id AND tt.meta_key = '_elementor_template_type'
      WHERE pm.meta_key = '_elementor_page_settings'
        AND tt.meta_value = 'kit'"
)->fetchAll(PDO::FETCH_ASSOC);

$kitFixedIds = [];
foreach ($kitRows as $row) {
    $postId = (int) $row['post_id'];
    $raw    = $row['meta_value'];
    [$kit, $wasRepaired] = unserialize_with_repair($raw);
    if ($kit === false) {
        $issues[] = "Elementor kit post {$postId} _elementor_page_settings unrecoverable";
        continue;
    }
    if ($wasRepaired) {
        $repaired = repair_lengths($raw);
        $upd = $pdo->prepare("UPDATE {$wpDb}.wp_postmeta SET meta_value = ? WHERE post_id = ? AND meta_key = '_elementor_page_settings'");
        $upd->execute([$repaired, $postId]);
        $kitFixedIds[] = $postId;
    }
}
if ($kitFixedIds) {
    $fixes[] = "Elementor kit settings repaired for post(s): " . implode(', ', $kitFixedIds);
    // Purge stale _elementor_css for the fixed kits AND for any post that
    // references them so styles regenerate from the repaired Kit.
    $pdo->exec("DELETE FROM {$wpDb}.wp_postmeta WHERE meta_key = '_elementor_css'");
    $fixes[] = "Stale _elementor_css purged sitewide (will regenerate on page load)";
}

// ---------------------------------------------------------------------------
// 9. WPO365 OAuth credentials — flag if empty.
//    The migration script clears application_id / application_secret /
//    tenant_id (they're prod secrets, intentionally not carried over).
//    Without them, "Login with Microsoft" fails and bounces to the homepage
//    instead of /wp-admin. Repopulating requires reading from prod, which
//    needs explicit user authorization — see Step 6.6 of SKILL.md.
// ---------------------------------------------------------------------------
$wpo365Raw = $pdo->query("SELECT option_value FROM {$wpDb}.wp_options WHERE option_name = 'wpo365_options'")->fetchColumn();
if ($wpo365Raw !== false) {
    [$wpo365, $wasRepaired] = unserialize_with_repair($wpo365Raw);
    if (is_array($wpo365)) {
        if ($wasRepaired) {
            $upd = $pdo->prepare("UPDATE {$wpDb}.wp_options SET option_value = ? WHERE option_name = 'wpo365_options'");
            $upd->execute([repair_lengths($wpo365Raw)]);
            $fixes[] = "wpo365_options serialization length prefixes repaired";
        }
        $empty = [];
        foreach (['application_id', 'application_secret', 'tenant_id'] as $k) {
            if (empty($wpo365[$k] ?? '')) {
                $empty[] = $k;
            }
        }
        if ($empty) {
            $warns[] = "WPO365 credentials missing: " . implode(', ', $empty)
                     . " — Microsoft login won't redirect to wp-admin until repopulated (see Step 6.6)";
        }
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
echo "\n=== Post-Migration Verification ===\n\n";

if (empty($issues) && empty($fixes) && empty($warns)) {
    echo "All settings verified OK.\n";
    exit(0);
}

if (!empty($fixes)) {
    echo "FIXES APPLIED:\n";
    foreach ($fixes as $f) {
        echo "  [FIXED] {$f}\n";
    }
    echo "\n";
}

if (!empty($warns)) {
    echo "WARNINGS (manual review):\n";
    foreach ($warns as $w) {
        echo "  [WARN] {$w}\n";
    }
    echo "\n";
}

if (!empty($issues)) {
    echo "UNRESOLVED ISSUES:\n";
    foreach ($issues as $i) {
        echo "  [ERROR] {$i}\n";
    }
    echo "\n";
    exit(1);
}

echo "Done.\n";
exit(0);
