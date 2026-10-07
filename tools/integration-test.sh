#!/usr/bin/env bash
# Integration tests against a real WordPress (run inside the "cli" container).
# Needs a FRESH environment (docker compose ... down -v first): the last section
# converts the site to multisite. Prints PASS/FAIL/SKIP; exits non-zero on any FAIL.
set -u
cd /var/www/html

FAILS=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
skip() { echo "SKIP  $1"; }
check() { # check "<name>" "<expected>" "<actual>"
	if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$2', got '$3')"; fi
}
status_field() { # status_field <php-expression-on-$d> [user]
	wp eval --user="${2:-admin}" "\$d = rest_do_request( new WP_REST_Request( 'GET', '/khseo/v1/status' ) )->get_data(); echo $1;"
}

# --- Install a fresh site ---------------------------------------------------
for i in $(seq 1 30); do wp db check >/dev/null 2>&1 && break; sleep 2; done
if wp core is-installed --network 2>/dev/null; then
	echo "Environment is already multisite from a previous run. Run: docker compose -f tools/docker-compose.yml down -v"
	exit 1
fi
wp core is-installed 2>/dev/null || wp core install --url=http://localhost:8089 --title="KHSEO Test" \
	--admin_user=admin --admin_password=admin-test-only --admin_email=admin@example.test --skip-email >/dev/null
wp user get editor1 >/dev/null 2>&1 || wp user create editor1 editor1@example.test --role=editor --user_pass=editor-test-only >/dev/null
rm -f wp-content/debug.log

# --- Activation -------------------------------------------------------------
wp plugin activate khseo >/dev/null 2>&1
check "plugin activates" "active" "$(wp plugin get khseo --field=status)"
check "db version recorded" "3" "$(wp option get khseo_db_version)"
check "settings seeded, AI off by default" "none" "$(wp eval 'echo get_option("khseo_settings")["ai_provider"];')"
check "admin has manage_khseo_ai" "1" "$(wp eval 'echo (int) get_role("administrator")->has_cap("manage_khseo_ai");')"
check "editor has view_khseo" "1" "$(wp eval 'echo (int) get_role("editor")->has_cap("view_khseo");')"
check "editor lacks manage_khseo_settings" "0" "$(wp eval 'echo (int) get_role("editor")->has_cap("manage_khseo_settings");')"
check "editor lacks manage_khseo_ai" "0" "$(wp eval 'echo (int) get_role("editor")->has_cap("manage_khseo_ai");')"
check "editor lacks rollback_khseo_changes" "0" "$(wp eval 'echo (int) get_role("editor")->has_cap("rollback_khseo_changes");')"

# --- REST permissions (internal dispatch) ----------------------------------
REST='$r = rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) ); echo $r->get_status();'
check "REST /status anonymous -> 401" "401" "$(wp eval "$REST")"
check "REST /status editor -> 200 (view only)" "200" "$(wp eval --user=editor1 "$REST")"
check "REST /status admin -> 200" "200" "$(wp eval --user=admin "$REST")"
check "REST: AI state not_configured" "not_configured" "$(status_field '$d["ai"]["state"]')"
check "REST: Search Console UNKNOWN" "UNKNOWN" "$(status_field '$d["search_console"]["status"]')"
check "REST: encryption key source = wp-config salts" "wp-config salts" "$(status_field '$d["encryption"]["key_source"]')"

# --- Real site checks: flip blog_public and see the finding change ---------
FINDING='foreach ( $d["findings"] as $f ) { if ( "RULE" === $f["rule"] ) { echo (int) $f["passed"]; } }'
wp option update blog_public 0 >/dev/null
check "SEO-INDEX-001 fails when indexing discouraged" "0" "$(status_field "''; ${FINDING//RULE/SEO-INDEX-001}")"
wp option update blog_public 1 >/dev/null
check "SEO-INDEX-001 passes when indexing allowed" "1" "$(status_field "''; ${FINDING//RULE/SEO-INDEX-001}")"
check "SEO-HTTPS-001 fails on http:// test site" "0" "$(status_field "''; ${FINDING//RULE/SEO-HTTPS-001}")"

# --- Secrets: encrypted with real salts, never in REST output --------------
wp eval '$s = KHSEO\Security\SecretStore::forSite(); update_option( "khseo_secrets", array( "ai_api_key" => $s->encrypt( "sk-integration-secret-123456" ) ), false );'
check "stored key is not plaintext" "0" "$(wp eval 'echo (int) str_contains( (string) get_option("khseo_secrets")["ai_api_key"], "sk-integration" );')"
check "stored key decrypts with site salts" "sk-integration-secret-123456" "$(wp eval 'echo KHSEO\Security\SecretStore::forSite()->decrypt( get_option("khseo_secrets")["ai_api_key"] );')"
check "REST output never contains the key" "0" "$(wp eval --user=admin 'echo (int) str_contains( wp_json_encode( rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data() ), "sk-integration" );')"
check "REST output never contains ciphertext" "0" "$(wp eval --user=admin 'echo (int) str_contains( wp_json_encode( rest_do_request( new WP_REST_Request( "GET", "/khseo/v1/status" ) )->get_data() ), "khseo1:" );')"
check "tampered ciphertext -> NULL, no fatal" "NULL" "$(wp eval 'var_export( KHSEO\Security\SecretStore::forSite()->decrypt( "khseo1:AAAA" . substr( get_option("khseo_secrets")["ai_api_key"], 11 ) ) );')"

# D5 regression: a saved key is NOT "AI working".
wp eval '$s = get_option("khseo_settings"); $s["ai_provider"] = "openai"; update_option( "khseo_settings", $s );'
check "REST: key saved -> state key_saved" "key_saved" "$(status_field '$d["ai"]["state"]')"
check "REST: AI features reported PLANNED" "PLANNED" "$(status_field '$d["ai"]["features"]')"
check "REST: AI message says nothing is sent" "1" "$(status_field '(int) str_contains( $d["ai"]["message"], "Nothing is sent" )')"

# --- Settings sanitizer runs on EVERY write path (here: WP-CLI, not wp-admin) --
wp eval --user=admin 'update_option( "khseo_settings", array( "ai_provider" => "skynet", "log_retention_days" => 9999, "evil" => "x" ) );'
check "invalid provider rejected on save" "openai" "$(wp eval 'echo get_option("khseo_settings")["ai_provider"];')"
check "unknown key dropped on save" "0" "$(wp eval 'echo (int) array_key_exists( "evil", get_option("khseo_settings") );')"
wp option update khseo_settings '"not-an-array"' --format=json >/dev/null 2>&1
check "non-array option value is normalized, no fatal" "openai" "$(wp eval 'echo KHSEO\Core\Plugin::instance()->container()->get("settings")["ai_provider"];' 2>&1 | tail -1)"

# --- Logger: bounded, single-line, redacted (D3 regression) ----------------
wp eval '$l = KHSEO\Core\Plugin::instance()->container()->get( KHSEO\Support\Logger::class ); $l->log( "security", "line1" . PHP_EOL . "FAKE ENTRY sk-abcdefghijklmnop1234 " . str_repeat( "A", 1000000 ) );'
LAST='$e = get_option("khseo_log"); $m = end($e)["message"];'
check "log entry size is capped" "1" "$(wp eval "$LAST echo (int) ( strlen( \$m ) <= 520 );")"
check "log entry has no newline (no forged lines)" "0" "$(wp eval "$LAST echo (int) str_contains( \$m, PHP_EOL );")"
check "log entry has no API key" "0" "$(wp eval "$LAST echo (int) str_contains( \$m, 'sk-abcdefghijklmnop' );")"
check "log option stays small" "1" "$(wp eval 'echo (int) ( strlen( serialize( get_option("khseo_log") ) ) < 200000 );')"

# --- Safe fetcher inside WordPress -----------------------------------------
FETCH='$f = KHSEO\Core\Plugin::instance()->container()->get( KHSEO\Http\SafeFetcher::class ); $r = $f->fetch( "URL" ); echo $r->ok ? "ok" : "blocked";'
for target in "http://wordpress/" "http://db:3306/" "http://127.0.0.1/" "http://localhost:8089/" "http://169.254.169.254/" "http://2130706433/" "http://[::1]/"; do
	check "fetcher refuses internal target $target" "blocked" "$(wp eval "${FETCH//URL/$target}")"
done
# Pinning proof: a name that does NOT exist in DNS is mapped (by our resolver) to a real
# public IP. If the request succeeds, curl connected to the validated IP without its own lookup.
if PUBLIC_IP=$(wp eval '$ips = KHSEO\Security\UrlGuard::systemResolve( "example.com" ); foreach ( $ips as $ip ) { if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) { echo $ip; break; } }') && [ -n "$PUBLIC_IP" ]; then
	PIN="\$g = new KHSEO\Security\UrlGuard( static fn ( \$h ) => 'khseo-pin-test.invalid' === \$h ? array( '$PUBLIC_IP' ) : array() );
		\$f = new KHSEO\Http\SafeFetcher( \$g, new KHSEO\Http\WpHttpTransport(), new KHSEO\Http\FetchPolicy( allowed_types: array( 'text/html', 'text/plain', 'application/json' ) ) );
		\$r = \$f->fetch( 'http://khseo-pin-test.invalid/' );
		echo ( \$r->ok || str_starts_with( \$r->reason, 'Content type' ) ) ? 'connected' : \$r->reason;"
	check "transport connects to the pinned IP (no second DNS lookup)" "connected" "$(wp eval "$PIN")"
	UNPINNED="\$r = wp_remote_get( 'http://khseo-pin-test.invalid/', array( 'timeout' => 5 ) ); echo is_wp_error( \$r ) ? 'dns-failed' : 'reached';"
	check "control: same URL WITHOUT pinning cannot be reached" "dns-failed" "$(wp eval "$UNPINNED")"
else
	skip "pinning proof (no outbound network/DNS in this environment)"
fi

# --- Admin screens render for the right users ------------------------------
SETTINGS_HTML='ob_start(); $m = new KHSEO\Admin\AdminModule(); $m->register( KHSEO\Core\Plugin::instance()->container() ); $m->renderSettings(); $h = ob_get_clean();'
check "Overview renders for admin" "1" "$(wp eval --user=admin 'ob_start(); $m = new KHSEO\Admin\AdminModule(); $m->register( KHSEO\Core\Plugin::instance()->container() ); $m->renderOverview(); echo (int) str_contains( ob_get_clean(), "KHSEO Overview" );' 2>/dev/null)"
check "Settings HTML has no API key" "0" "$(wp eval --user=admin "$SETTINGS_HTML echo (int) str_contains( \$h, 'sk-integration' );" 2>/dev/null)"
check "Settings HTML has no ciphertext" "0" "$(wp eval --user=admin "$SETTINGS_HTML echo (int) str_contains( \$h, 'khseo1:' );" 2>/dev/null)"
check "Settings page shows masked key" "1" "$(wp eval --user=admin "$SETTINGS_HTML echo (int) str_contains( \$h, '••••3456' );" 2>/dev/null)"

# --- Real HTTP: login, nonce and capability enforcement on admin-post -------
# curl refuses cookies for single-label hosts ("wordpress"), so use a dotted name routed to the container.
BASE=http://khseo.test
CURL_TO="--connect-to khseo.test:80:wordpress:80"
login() { # login <user> <pass> <cookiejar>
	curl -s $CURL_TO -o /dev/null -c "$3" -b "wordpress_test_cookie=WP%20Cookie%20check" \
		--data-urlencode "log=$1" --data-urlencode "pwd=$2" -d "testcookie=1" -d "redirect_to=$BASE/wp-admin/" "$BASE/wp-login.php"
}
post_key() { # post_key <cookiejar> <extra curl args...>; prints HTTP status
	local jar=$1; shift
	curl -s $CURL_TO -o /dev/null -w '%{http_code}' -b "$jar" -d "action=khseo_save_ai_key" -d "khseo_ai_api_key=sk-http-attack-999999" "$@" "$BASE/wp-admin/admin-post.php"
}
KEY_IS='echo (int) ( KHSEO\Security\SecretStore::forSite()->decrypt( get_option("khseo_secrets")["ai_api_key"] ?? "" ) === "sk-http-attack-999999" );'
ADMIN_JAR=/tmp/admin.jar; EDITOR_JAR=/tmp/editor.jar; rm -f /tmp/*.jar
login admin admin-test-only $ADMIN_JAR
login editor1 editor-test-only $EDITOR_JAR

check "HTTP: anonymous REST /status -> 401" "401" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' "$BASE/?rest_route=/khseo/v1/status")"
post_key /dev/null >/dev/null
check "HTTP: anonymous admin-post does not change the key" "0" "$(wp eval "$KEY_IS")"
check "HTTP: editor admin-post -> 403" "403" "$(post_key $EDITOR_JAR)"
check "HTTP: admin WITHOUT nonce -> 403" "403" "$(post_key $ADMIN_JAR)"
check "HTTP: admin with FORGED nonce -> 403" "403" "$(post_key $ADMIN_JAR -d '_wpnonce=deadbeef00')"
check "HTTP: key unchanged after rejected requests" "0" "$(wp eval "$KEY_IS")"
NONCE=$(curl -s $CURL_TO -b $ADMIN_JAR "$BASE/wp-admin/admin.php?page=khseo-settings" | grep -o 'name="_wpnonce" value="[0-9a-f]*"' | tail -1 | grep -o '[0-9a-f]\{10\}')
if [ -n "$NONCE" ]; then
	check "HTTP: admin with valid nonce -> 302 redirect" "302" "$(post_key $ADMIN_JAR -d "_wpnonce=$NONCE" -e "$BASE/wp-admin/admin.php?page=khseo-settings")"
	check "HTTP: key saved encrypted via the real form handler" "1" "$(wp eval "$KEY_IS")"
	check "HTTP: settings page never echoes the key" "0" "$(curl -s $CURL_TO -b $ADMIN_JAR "$BASE/wp-admin/admin.php?page=khseo-settings" | grep -c 'sk-http-attack')"
else
	fail "HTTP: could not read the settings-page nonce (login failed?)"
fi
check "HTTP: editor cannot open the Settings screen" "403" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' -b $EDITOR_JAR "$BASE/wp-admin/admin.php?page=khseo-settings")"
check "HTTP: editor can open the Overview screen" "200" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' -b $EDITOR_JAR "$BASE/wp-admin/admin.php?page=khseo")"

# --- Phase 2: audits, findings, score, fixes (separate file) ---------------
# shellcheck source=/dev/null
. /plugin/tools/integration-phase2.inc.sh

# --- No PHP notices/warnings from KHSEO -------------------------------------
if [ -f wp-content/debug.log ] && grep -qi "khseo" wp-content/debug.log; then
	fail "debug.log has KHSEO notices:"; grep -i khseo wp-content/debug.log | head -5
else
	pass "no KHSEO notices in debug.log"
fi

# --- Uninstall keeps data by default, deletes only when opted in ----------
wp plugin deactivate khseo >/dev/null
check "deactivation keeps settings" "1" "$(wp eval 'echo (int) is_array( get_option("khseo_settings") );')"
check "deactivation keeps capabilities" "1" "$(wp eval 'echo (int) get_role("administrator")->has_cap("view_khseo");')"
wp eval 'define( "WP_UNINSTALL_PLUGIN", "khseo/khseo.php" ); include WP_PLUGIN_DIR . "/khseo/uninstall.php";'
check "default uninstall keeps settings" "1" "$(wp eval 'echo (int) is_array( get_option("khseo_settings") );')"
# Plugin is inactive here, so KHSEO classes are not loaded: plain arrays only.
wp eval '$s = get_option("khseo_settings"); $s = is_array( $s ) ? $s : array(); $s["delete_data_on_uninstall"] = true; update_option( "khseo_settings", $s );'
check "opt-in flag saved while inactive" "1" "$(wp eval 'echo (int) ( true === get_option("khseo_settings")["delete_data_on_uninstall"] );')"
wp eval 'define( "WP_UNINSTALL_PLUGIN", "khseo/khseo.php" ); include WP_PLUGIN_DIR . "/khseo/uninstall.php";'
check "opted-in uninstall deletes settings" "0" "$(wp eval 'echo (int) ( false !== get_option("khseo_settings") );')"
check "opted-in uninstall deletes secrets" "0" "$(wp eval 'echo (int) ( false !== get_option("khseo_secrets") );')"
check "opted-in uninstall removes caps" "0" "$(wp eval 'echo (int) get_role("administrator")->has_cap("view_khseo");')"
check "opted-in uninstall drops the findings table" "" "$(wp db query "SHOW TABLES LIKE 'wp_khseo_issues'" --skip-column-names)"

# --- Multisite ---------------------------------------------------------------
if wp core multisite-convert --title="KHSEO Network" >/dev/null 2>&1; then
	wp site create --slug=site2 >/dev/null
	wp plugin activate khseo --network >/dev/null 2>&1
	S2=http://localhost:8089/site2/
	check "multisite: network-activated" "1" "$(wp eval 'echo (int) is_plugin_active_for_network( "khseo/khseo.php" );')"
	check "multisite: main site admin has caps" "1" "$(wp eval 'echo (int) get_role("administrator")->has_cap("manage_khseo");')"
	check "multisite: existing site2 got caps on network activation" "1" "$(wp --url=$S2 eval 'echo (int) get_role("administrator")->has_cap("manage_khseo");')"
	wp site create --slug=site3 >/dev/null
	S3=http://localhost:8089/site3/
	check "multisite: site created AFTER activation gets caps on first boot" "1" "$(wp --url=$S3 eval 'echo (int) get_role("administrator")->has_cap("manage_khseo");')"
	check "multisite: site3 editor stays view-only" "0" "$(wp --url=$S3 eval 'echo (int) get_role("editor")->has_cap("manage_khseo_settings");')"
	wp --url=$S2 eval '$s = KHSEO\Settings\Settings::normalize( get_option("khseo_settings") ); $s["compatibility_mode"] = "primary"; $s["delete_data_on_uninstall"] = true; update_option( "khseo_settings", $s );'
	check "multisite: settings are per site (site2 changed)" "primary" "$(wp --url=$S2 eval 'echo get_option("khseo_settings")["compatibility_mode"];')"
	check "multisite: settings are per site (main untouched)" "advisory" "$(wp eval 'echo KHSEO\Settings\Settings::normalize( get_option("khseo_settings") )["compatibility_mode"];')"
	# Real order: WordPress only uninstalls a plugin after it is deactivated network-wide.
	# Check the tables directly: get_option() returns registered defaults for a missing row.
	wp plugin deactivate khseo --network >/dev/null
	wp eval 'define( "WP_UNINSTALL_PLUGIN", "khseo/khseo.php" ); include WP_PLUGIN_DIR . "/khseo/uninstall.php";'
	check "multisite: opted-in site2 has no KHSEO rows" "0" "$(wp db query "SELECT COUNT(*) FROM wp_2_options WHERE option_name LIKE 'khseo%'" --skip-column-names)"
	check "multisite: opted-in site2 caps removed" "0" "$(wp --url=$S2 eval 'echo (int) get_role("administrator")->has_cap("manage_khseo");')"
	check "multisite: main site (not opted in) keeps caps" "1" "$(wp eval 'echo (int) get_role("administrator")->has_cap("manage_khseo");')"
	check "multisite: site3 (not opted in) keeps its rows" "1" "$(wp db query "SELECT COUNT(*) > 0 FROM wp_3_options WHERE option_name = 'khseo_db_version'" --skip-column-names)"
	check "multisite: site3 (not opted in) keeps caps" "1" "$(wp --url=$S3 eval 'echo (int) get_role("administrator")->has_cap("manage_khseo");')"
else
	skip "multisite (wp core multisite-convert failed in this environment)"
fi

echo "----"
if [ "$FAILS" -eq 0 ]; then echo "ALL INTEGRATION CHECKS PASSED"; else echo "$FAILS CHECK(S) FAILED"; fi
exit "$FAILS"
