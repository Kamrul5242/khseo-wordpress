# Phase 2 integration checks. Sourced by integration-test.sh after the HTTP login
# section: uses check/pass/fail, $BASE, $CURL_TO, $ADMIN_JAR and $EDITOR_JAR.
# Audits run INSIDE the WordPress container (REST requests), fetching the site's
# own URL http://localhost:8089 through SafeFetcher.

echo "--- Phase 2 ---"

# Pretty permalinks so /robots.txt and /wp-sitemap.xml are served by WordPress.
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1

# Test-only fixture plugin: deliberate defects on specific pages.
mkdir -p wp-content/mu-plugins
cat > wp-content/mu-plugins/khseo-fixtures.php <<'PHP'
<?php
// KHSEO integration-test fixtures only. Injects deliberate SEO defects.
add_action( 'wp_head', static function () {
	if ( is_page( 'hidden-page' ) ) {
		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
	}
	if ( is_page( 'schema-bad' ) ) {
		echo '<script type="application/ld+json">{ "@context": "https://schema.org", "@type": "Product", broken }</script>' . "\n";
	}
	if ( is_page( 'xss-page' ) ) {
		echo '<meta name="description" content="&lt;script&gt;alert(&#039;khseo-xss&#039;)&lt;/script&gt; short">' . "\n";
	}
}, 1 );
PHP
for slug in about-us hidden-page schema-bad xss-page; do
	wp post list --post_type=page --name=$slug --format=ids | grep -q . || \
		wp post create --post_type=page --post_status=publish --post_name=$slug --post_title="Page $slug" \
			--post_content="<p>Content for $slug. <a href=\"/about-us/\">About us</a> <a href=\"/hidden-page/\">Hidden</a></p>" >/dev/null
done
check "fixture pages published" "4" "$(wp post list --post_type=page --post_status=publish --post_name__in=about-us,hidden-page,schema-bad,xss-page --format=count)"
check "migration v3: findings table exists" "wp_khseo_issues" "$(wp db query "SHOW TABLES LIKE 'wp_khseo_issues'" --skip-column-names)"
check "findings table has the url_hash index" "1" "$(wp db query "SHOW INDEX FROM wp_khseo_issues" --skip-column-names | grep -c -w url_hash)"

# REST over real HTTP: cookie auth needs the wp_rest nonce.
REST_NONCE=$(curl -s $CURL_TO -b $ADMIN_JAR "$BASE/wp-admin/admin-ajax.php?action=rest-nonce")
EDITOR_NONCE=$(curl -s $CURL_TO -b $EDITOR_JAR "$BASE/wp-admin/admin-ajax.php?action=rest-nonce")
rest() { # rest <METHOD> <path> <jar> <nonce> [curl args…]; prints "STATUS BODY"
	local method=$1 path=$2 jar=$3 nonce=$4; shift 4
	curl -s $CURL_TO -o /tmp/rest.body -w '%{http_code}' -X "$method" -b "$jar" -H "X-WP-Nonce: $nonce" "$@" "$BASE/?rest_route=$path"
	printf ' '; head -c 4000 /tmp/rest.body
}
code() { rest "$@" | cut -d' ' -f1; }
json() { # json <php-expr-on-$d> — evaluates against the last response body
	php -r '$d = json_decode( file_get_contents( "/tmp/rest.body" ), true ); echo '"$1"';'
}

# Permissions.
check "REST: anonymous GET /issues -> 401" "401" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' "$BASE/?rest_route=/khseo/v1/issues")"
check "REST: cookie without nonce cannot start an audit" "401" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' -X POST -b $ADMIN_JAR -d scope=homepage "$BASE/?rest_route=/khseo/v1/audit")"
check "REST: editor can list issues" "200" "$(code GET /khseo/v1/issues $EDITOR_JAR $EDITOR_NONCE)"
check "REST: editor cannot start an audit" "403" "$(code POST /khseo/v1/audit $EDITOR_JAR $EDITOR_NONCE -d scope=homepage)"
check "REST: editor cannot preview fixes" "403" "$(code POST /khseo/v1/fixes/preview $EDITOR_JAR $EDITOR_NONCE -d issue_id=1)"

# Input validation and SSRF at the API edge.
check "REST: unknown scope -> 400" "400" "$(code POST /khseo/v1/audit $ADMIN_JAR $REST_NONCE -d scope=everything)"
check "REST: audit of another site's URL refused" "409" "$(code POST /khseo/v1/audit $ADMIN_JAR $REST_NONCE -d scope=url -d url=https://example.com/)"
check "REST: audit of metadata endpoint refused" "409" "$(code POST /khseo/v1/audit $ADMIN_JAR $REST_NONCE -d scope=url --data-urlencode url=http://169.254.169.254/latest/meta-data/)"
check "REST: per_page above 100 rejected" "400" "$(code GET '/khseo/v1/issues&per_page=1000' $ADMIN_JAR $REST_NONCE)"
check "REST: invalid severity rejected" "400" "$(code GET '/khseo/v1/issues&severity=P9' $ADMIN_JAR $REST_NONCE)"

# Full sitemap audit, stepped like the dashboard does. Indexing must be ON here:
# WordPress itself disables /wp-sitemap.xml when search engines are discouraged.
wp option update blog_public 1 >/dev/null
check "REST: start sitemap audit -> 202" "202" "$(code POST /khseo/v1/audit $ADMIN_JAR $REST_NONCE -d scope=sitemap)"
check "REST: second concurrent audit refused" "409" "$(code POST /khseo/v1/audit $ADMIN_JAR $REST_NONCE -d scope=homepage)"
STATUS=running
for i in $(seq 1 40); do
	rest POST /khseo/v1/audit/step $ADMIN_JAR $REST_NONCE >/dev/null
	STATUS=$(json '$d["audit"]["status"] ?? "none"')
	[ "$STATUS" = "running" ] || break
done
check "audit finished" "done" "$STATUS"
rest GET /khseo/v1/audit $ADMIN_JAR $REST_NONCE >/dev/null
check "last audit has a numeric score 0–100" "1" "$(json '(int) ( is_int( $d["last_audit"]["score"]["score"] ) && $d["last_audit"]["score"]["score"] >= 0 && $d["last_audit"]["score"]["score"] <= 100 )')"
check "score disclaimer: not a Google ranking score" "1" "$(json '(int) str_contains( $d["last_audit"]["score"]["disclaimer"], "not a Google ranking score" )')"
check "scope label names the sitemap sample" "1" "$(json '(int) str_contains( $d["last_audit"]["scope"], "sitemap URL(s)" )')"
check "scope label never counts the homepage twice" "1" "$(json '(int) ( preg_match( "/^(\\d+) URL\\(s\\): (\\d+) of \\d+ sitemap URL\\(s\\), including the homepage$/", $d["last_audit"]["scope"], $m ) && $m[1] === $m[2] )')"
check "audit analysed the homepage + fixture pages" "1" "$(json '(int) ( $d["last_audit"]["coverage"]["urls_analysed"] >= 5 )')"
check "coverage says JS rendering NOT TESTED" "NOT TESTED" "$(json '$d["last_audit"]["coverage"]["js_rendering"]')"
check "coverage says Google index status NOT TESTED" "1" "$(json '(int) str_starts_with( $d["last_audit"]["coverage"]["google_index_status"], "NOT TESTED" )')"

issue_count() { rest GET "/khseo/v1/issues&$1" $ADMIN_JAR $REST_NONCE >/dev/null; json '$d["total"]'; }
check "finding: noindex page (SEO-INDEX-002)" "1" "$(issue_count 'url=hidden-page&per_page=100' >/dev/null; json '(int) in_array( "SEO-INDEX-002", array_column( $d["items"], "rule_id" ), true )')"
check "finding: invalid JSON-LD (SEO-SCHEMA-001)" "1" "$(issue_count 'url=schema-bad&per_page=100' >/dev/null; json '(int) in_array( "SEO-SCHEMA-001", array_column( $d["items"], "rule_id" ), true )')"
check "robots.txt was read (no ROBOTS-001 finding)" "0" "$(issue_count 'url=site&per_page=100' >/dev/null; json '(int) in_array( "SEO-ROBOTS-001", array_column( $d["items"], "rule_id" ), true )')"
check "sitemap was found (no SITEMAP-001 finding)" "0" "$(json '(int) in_array( "SEO-SITEMAP-001", array_column( $d["items"], "rule_id" ), true )')"
check "findings are deduplicated (unique finding keys)" "0" "$(wp db query "SELECT COUNT(*) - COUNT(DISTINCT finding_key) FROM wp_khseo_issues" --skip-column-names)"
check "SQL-injection string in the URL filter is inert" "0" "$(issue_count "url=%27%20OR%201%3D1%20--%20")"
check "pagination headers" "1" "$(curl -s $CURL_TO -D - -o /dev/null -b $ADMIN_JAR -H "X-WP-Nonce: $REST_NONCE" "$BASE/?rest_route=/khseo/v1/issues&per_page=2" | grep -ci '^X-WP-TotalPages')"
check "unknown issue id -> 404" "404" "$(code GET /khseo/v1/issues/999999 $ADMIN_JAR $REST_NONCE)"

# XSS: a page-controlled payload is stored as data and escaped on screen.
rest GET '/khseo/v1/issues&url=xss-page&per_page=100' $ADMIN_JAR $REST_NONCE >/dev/null
XSS_ID=$(json 'array_values( array_filter( $d["items"], fn( $i ) => "SEO-META-006" === $i["rule_id"] ) )[0]["id"] ?? 0')
check "finding: description with markup (SEO-META-006)" "1" "$([ "${XSS_ID:-0}" -gt 0 ] && echo 1 || echo 0)"
XSS_PAGE=$(curl -s $CURL_TO -b $ADMIN_JAR "$BASE/wp-admin/admin.php?page=khseo-issues&issue=$XSS_ID")
check "issue screen shows the payload escaped" "1" "$(echo "$XSS_PAGE" | grep -c "&lt;script&gt;alert(&#039;khseo-xss&#039;)" | tr -d ' ' | sed 's/^[1-9][0-9]*$/1/')"
check "issue screen never outputs the raw payload" "0" "$(echo "$XSS_PAGE" | grep -c "<script>alert('khseo-xss')")"

# Homepage audit with indexing OFF: P0 finding, score cap, and a governed fix.
wp option update blog_public 0 >/dev/null
check "REST: start homepage audit" "202" "$(code POST /khseo/v1/audit $ADMIN_JAR $REST_NONCE -d scope=homepage)"
for i in $(seq 1 20); do rest POST /khseo/v1/audit/step $ADMIN_JAR $REST_NONCE >/dev/null; [ "$(json '$d["audit"]["status"] ?? "none"')" = "running" ] || break; done
rest GET /khseo/v1/audit $ADMIN_JAR $REST_NONCE >/dev/null
check "homepage scope label" "Homepage only (1 URL)" "$(json '$d["last_audit"]["scope"]')"
check "score is capped by the P0 (blog_public=0)" "1" "$(json '(int) ( $d["last_audit"]["score"]["capped"] || $d["last_audit"]["score"]["score"] <= 49 )')"
check "finding: site discourages indexing (SEO-INDEX-001, fix available)" "1" "$(issue_count 'url=site&per_page=100' >/dev/null; json '(int) ( array_values( array_filter( $d["items"], fn( $i ) => "SEO-INDEX-001" === $i["rule_id"] ) )[0]["fix_available"] ?? false )')"

# Governed fix: preview → (apply without approval refused) → approve → apply → validate → rollback.
SITE_ID=$(issue_count 'url=site&per_page=100' >/dev/null; json 'array_values( array_filter( $d["items"], fn( $i ) => "SEO-INDEX-001" === $i["rule_id"] ) )[0]["id"] ?? 0')
rest POST /khseo/v1/fixes/preview $ADMIN_JAR $REST_NONCE -d issue_id=$SITE_ID >/dev/null
CHANGE=$(json '$d["change_id"] ?? ""')
check "preview: R3 change with before/after" "R3|0 (Discourage search engines: ticked)" "$(json '$d["risk"] . "|" . $d["before"]')"
check "apply WITHOUT approval refused (ChangeGate)" "409" "$(code POST /khseo/v1/fixes/apply $ADMIN_JAR $REST_NONCE -d change_id=$CHANGE)"
check "nothing changed without approval" "0" "$(wp option get blog_public)"
check "editor cannot approve" "403" "$(code POST /khseo/v1/fixes/approve $EDITOR_JAR $EDITOR_NONCE -d change_id=$CHANGE)"
check "forged change id refused" "409" "$(code POST /khseo/v1/fixes/approve $ADMIN_JAR $REST_NONCE -d change_id=0123456789abcdef0123456789abcdef)"
check "approve this exact change" "200" "$(code POST /khseo/v1/fixes/approve $ADMIN_JAR $REST_NONCE -d change_id=$CHANGE)"
check "apply approved change" "200" "$(code POST /khseo/v1/fixes/apply $ADMIN_JAR $REST_NONCE -d change_id=$CHANGE)"
JOURNAL=$(json '$d["journal_id"] ?? ""')
check "validated: blog_public is now 1" "1" "$(wp option get blog_public)"
check "approval is single-use" "409" "$(code POST /khseo/v1/fixes/apply $ADMIN_JAR $REST_NONCE -d change_id=$CHANGE)"
check "rollback restores the previous value" "200" "$(code POST /khseo/v1/fixes/rollback $ADMIN_JAR $REST_NONCE -d journal_id=$JOURNAL)"
check "after rollback blog_public is 0" "0" "$(wp option get blog_public)"
rest POST /khseo/v1/fixes/approve $ADMIN_JAR $REST_NONCE -d change_id=$CHANGE >/dev/null
rest POST /khseo/v1/fixes/apply $ADMIN_JAR $REST_NONCE -d change_id=$CHANGE >/dev/null
JOURNAL2=$(json '$d["journal_id"] ?? ""')
wp option update blog_public 0 >/dev/null   # Someone changes it again after KHSEO.
check "rollback BLOCKED when the value changed since" "409" "$(code POST /khseo/v1/fixes/rollback $ADMIN_JAR $REST_NONCE -d journal_id=$JOURNAL2)"
check "blocked rollback modified nothing" "0" "$(wp option get blog_public)"
wp option update blog_public 1 >/dev/null

# Re-audit resolves fixed findings; ignored ones stay ignored.
HIDDEN_ID=$(issue_count 'url=hidden-page&per_page=100' >/dev/null; json 'array_values( array_filter( $d["items"], fn( $i ) => "SEO-INDEX-002" === $i["rule_id"] ) )[0]["id"] ?? 0')
check "ignore an issue" "200" "$(code POST /khseo/v1/issues/$HIDDEN_ID/status $ADMIN_JAR $REST_NONCE -d status=ignored)"
code POST /khseo/v1/audit $ADMIN_JAR $REST_NONCE -d scope=sitemap >/dev/null
# (indexing is ON again after the fix, so the sitemap is available)
for i in $(seq 1 40); do rest POST /khseo/v1/audit/step $ADMIN_JAR $REST_NONCE >/dev/null; [ "$(json '$d["audit"]["status"] ?? "none"')" = "running" ] || break; done
check "re-audit: SEO-INDEX-001 resolved after the fix" "resolved" "$(wp db query "SELECT status FROM wp_khseo_issues WHERE id = $SITE_ID" --skip-column-names)"
check "re-audit: ignored issue stays ignored" "ignored" "$(wp db query "SELECT status FROM wp_khseo_issues WHERE id = $HIDDEN_ID" --skip-column-names)"

# Locking: a held lock means a step does no work.
wp eval 'add_option( "khseo_audit_lock", time() + 60, "", false ); $r = KHSEO\Core\Plugin::instance()->container()->get( KHSEO\Audit\AuditRunner::class ); update_option( "khseo_audit_job", array( "id" => str_repeat( "a", 32 ), "scope" => "homepage", "status" => "running", "phase" => "discover", "queue" => array( "x" ), "pages" => array(), "failed" => array(), "notes" => array(), "started" => time(), "finished" => 0, "updated" => time() ), false ); $r->step(); delete_option( "khseo_audit_lock" );'
check "locked step left the job untouched" "discover" "$(wp eval 'echo get_option("khseo_audit_job")["phase"];')"
wp eval 'KHSEO\Audit\AuditRunner::cancel();'
check "cancel stops a running audit" "cancelled" "$(wp eval 'echo get_option("khseo_audit_job")["status"];')"

# Admin screens render for the right users.
check "Issues screen renders for admin" "1" "$(curl -s $CURL_TO -b $ADMIN_JAR "$BASE/wp-admin/admin.php?page=khseo-issues" | grep -c 'KHSEO Issues' | sed 's/^[1-9][0-9]*$/1/')"
check "Issues screen with SQLi-looking filter still renders" "200" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' -b $ADMIN_JAR "$BASE/wp-admin/admin.php?page=khseo-issues&severity=P1%27--&url=%27%20OR%201%3D1")"
check "editor can view Issues" "200" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' -b $EDITOR_JAR "$BASE/wp-admin/admin.php?page=khseo-issues")"
check "editor cannot start an audit via admin-post" "403" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' -b $EDITOR_JAR -d action=khseo_audit_start -d scope=homepage "$BASE/wp-admin/admin-post.php")"
check "admin-post audit start without nonce refused" "403" "$(curl -s $CURL_TO -o /dev/null -w '%{http_code}' -b $ADMIN_JAR -d action=khseo_audit_start -d scope=homepage "$BASE/wp-admin/admin-post.php")"
check "no secrets in REST status" "0" "$(rest GET /khseo/v1/status $ADMIN_JAR $REST_NONCE | grep -c -E 'sk-|khseo1:')"
rm -f wp-content/mu-plugins/khseo-fixtures.php
