#!/usr/bin/env bash
# Integration smoke test against a real WordPress (run inside the "cli" container).
# Every check prints PASS/FAIL; the script exits non-zero on any failure.
set -u
cd /var/www/html

FAILS=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
check() { # check "<name>" "<expected>" "<actual>"
	if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$2', got '$3')"; fi
}

# --- Install a fresh site ---------------------------------------------------
for i in $(seq 1 30); do wp db check >/dev/null 2>&1 && break; sleep 2; done
wp core is-installed 2>/dev/null || wp core install --url=http://localhost:8089 --title="KHSEO Test" \
	--admin_user=admin --admin_password=admin-test-only --admin_email=admin@example.test --skip-email >/dev/null
wp user get editor1 >/dev/null 2>&1 || wp user create editor1 editor1@example.test --role=editor --user_pass=editor-test-only >/dev/null
rm -f wp-content/debug.log

# --- Activation -------------------------------------------------------------
wp plugin activate khseo >/dev/null 2>&1
check "plugin activates" "active" "$(wp plugin get khseo --field=status)"
check "db version recorded" "1" "$(wp option get khseo_db_version)"
check "settings seeded, AI off by default" "none" "$(wp eval 'echo get_option("khseo_settings")["ai_provider"];')"
check "admin has manage_khseo_ai" "1" "$(wp eval 'echo (int) get_role("administrator")->has_cap("manage_khseo_ai");')"
check "editor has view_khseo" "1" "$(wp eval 'echo (int) get_role("editor")->has_cap("view_khseo");')"
check "editor lacks manage_khseo_settings" "0" "$(wp eval 'echo (int) get_role("editor")->has_cap("manage_khseo_settings");')"
check "editor lacks manage_khseo_ai" "0" "$(wp eval 'echo (int) get_role("editor")->has_cap("manage_khseo_ai");')"

# --- REST permissions -------------------------------------------------------
REST='$r = rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) ); echo $r->get_status();'
check "REST /status anonymous -> 401" "401" "$(wp eval "$REST")"
check "REST /status editor -> 200 (view only)" "200" "$(wp eval --user=editor1 "$REST")"
check "REST /status admin -> 200" "200" "$(wp eval --user=admin "$REST")"
check "REST status reports AI not configured" "0" "$(wp eval --user=admin '$d = rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data(); echo (int) $d["ai"]["configured"];')"
check "REST status: Search Console UNKNOWN" "UNKNOWN" "$(wp eval --user=admin '$d = rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data(); echo $d["search_console"]["status"];')"

# --- Real site checks: flip blog_public and see the finding change ---------
wp option update blog_public 0 >/dev/null
check "SEO-INDEX-001 fails when indexing discouraged" "0" "$(wp eval --user=admin '$d = rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data(); foreach ( $d["findings"] as $f ) { if ( "SEO-INDEX-001" === $f["rule"] ) { echo (int) $f["passed"]; } }')"
wp option update blog_public 1 >/dev/null
check "SEO-INDEX-001 passes when indexing allowed" "1" "$(wp eval --user=admin '$d = rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data(); foreach ( $d["findings"] as $f ) { if ( "SEO-INDEX-001" === $f["rule"] ) { echo (int) $f["passed"]; } }')"
check "SEO-HTTPS-001 fails on http:// test site" "0" "$(wp eval --user=admin '$d = rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data(); foreach ( $d["findings"] as $f ) { if ( "SEO-HTTPS-001" === $f["rule"] ) { echo (int) $f["passed"]; } }')"

# --- Secrets: encrypted with real salts, never in REST output --------------
wp eval '$s = new KHSEO\Security\SecretStore( KHSEO\Security\SecretStore::siteKeyMaterial() ); update_option( "khseo_secrets", array( "ai_api_key" => $s->encrypt( "sk-integration-secret-123456" ) ), false );'
check "stored key is not plaintext" "0" "$(wp eval 'echo (int) str_contains( (string) get_option("khseo_secrets")["ai_api_key"], "sk-integration" );')"
check "stored key decrypts with site salts" "sk-integration-secret-123456" "$(wp eval '$s = new KHSEO\Security\SecretStore( KHSEO\Security\SecretStore::siteKeyMaterial() ); echo $s->decrypt( get_option("khseo_secrets")["ai_api_key"] );')"
check "REST output never contains the key" "0" "$(wp eval --user=admin 'echo (int) str_contains( wp_json_encode( rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data() ), "sk-integration" );')"
check "REST output never contains ciphertext" "0" "$(wp eval --user=admin 'echo (int) str_contains( wp_json_encode( rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data() ), "khseo1:" );')"

# --- Settings sanitizer runs on EVERY write path (here: WP-CLI, not wp-admin) --
wp eval --user=admin 'update_option( "khseo_settings", array( "ai_provider" => "skynet", "log_retention_days" => 9999, "evil" => "x" ) );'
check "invalid provider rejected on save" "none" "$(wp eval 'echo get_option("khseo_settings")["ai_provider"];')"
check "unknown key dropped on save" "0" "$(wp eval 'echo (int) array_key_exists( "evil", get_option("khseo_settings") );')"

# --- Admin screens render for the right users ------------------------------
OVERVIEW='set_current_screen( "toplevel_page_khseo" ); ob_start(); KHSEO\Core\Plugin::instance()->container(); $m = new KHSEO\Admin\AdminModule(); $m->register( KHSEO\Core\Plugin::instance()->container() ); $m->renderOverview(); $h = ob_get_clean(); echo (int) str_contains( $h, "KHSEO Overview" );'
check "Overview renders for admin" "1" "$(wp eval --user=admin "$OVERVIEW" 2>/dev/null)"
check "Overview HTML has no API key" "0" "$(wp eval --user=admin 'ob_start(); $m = new KHSEO\Admin\AdminModule(); $m->register( KHSEO\Core\Plugin::instance()->container() ); $m->renderSettings(); echo (int) str_contains( ob_get_clean(), "sk-integration" );' 2>/dev/null)"
check "Settings page shows masked key" "1" "$(wp eval --user=admin 'ob_start(); $m = new KHSEO\Admin\AdminModule(); $m->register( KHSEO\Core\Plugin::instance()->container() ); $m->renderSettings(); echo (int) str_contains( ob_get_clean(), "••••3456" );' 2>/dev/null)"

# --- No PHP notices/warnings from KHSEO -------------------------------------
if [ -f wp-content/debug.log ] && grep -qi "khseo" wp-content/debug.log; then
	fail "debug.log has KHSEO notices:"; grep -i khseo wp-content/debug.log | head -5
else
	pass "no KHSEO notices in debug.log"
fi

# --- Uninstall keeps data by default, deletes only when opted in ----------
wp plugin deactivate khseo >/dev/null
check "deactivation keeps settings" "none" "$(wp eval 'echo get_option("khseo_settings")["ai_provider"];')"
wp eval 'define( "WP_UNINSTALL_PLUGIN", "khseo/khseo.php" ); include WP_PLUGIN_DIR . "/khseo/uninstall.php";'
check "default uninstall keeps settings" "1" "$(wp eval 'echo (int) is_array( get_option("khseo_settings") );')"
wp eval '$s = get_option("khseo_settings"); $s["delete_data_on_uninstall"] = true; update_option( "khseo_settings", $s );'
wp eval 'define( "WP_UNINSTALL_PLUGIN", "khseo/khseo.php" ); include WP_PLUGIN_DIR . "/khseo/uninstall.php";'
check "opted-in uninstall deletes settings" "0" "$(wp eval 'echo (int) ( false !== get_option("khseo_settings") );')"
check "opted-in uninstall deletes secrets" "0" "$(wp eval 'echo (int) ( false !== get_option("khseo_secrets") );')"
check "opted-in uninstall removes caps" "0" "$(wp eval 'echo (int) get_role("administrator")->has_cap("view_khseo");')"

echo "----"
if [ "$FAILS" -eq 0 ]; then echo "ALL INTEGRATION CHECKS PASSED"; else echo "$FAILS CHECK(S) FAILED"; fi
exit "$FAILS"
