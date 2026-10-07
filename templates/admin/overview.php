<?php
/**
 * Overview screen: score with its scope, coverage, open issues, audit controls.
 *
 * @package KHSEO
 * @var array<string, mixed> $report
 * @var string               $notice
 * @var bool                 $can_audit
 */

declare(strict_types=1);

use KHSEO\Admin\AuditScreens;
use KHSEO\Score\ScoreCalculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$khseo_last  = is_array( $report['last_audit'] ) ? $report['last_audit'] : null;
$khseo_job   = $report['audit'];
$khseo_open  = (array) $report['open_findings'];
$khseo_score = null === $khseo_last ? null : $khseo_last['score']['score'];
$khseo_cov   = null === $khseo_last ? array() : (array) $khseo_last['coverage'];
$khseo_issue = static fn ( string $filter, string $value ): string => add_query_arg(
	array(
		'page'  => AuditScreens::ISSUES_SLUG,
		$filter => $value,
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap khseo">
	<h1><?php esc_html_e( 'KHSEO Overview', 'khseo' ); ?></h1>
	<?php if ( '' !== $notice ) : ?>
		<div class="notice notice-info" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="khseo-cards">
		<section class="khseo-card khseo-score" aria-labelledby="khseo-score-h">
			<h2 id="khseo-score-h"><?php esc_html_e( 'KHSEO Score', 'khseo' ); ?></h2>
			<?php if ( null === $khseo_score ) : ?>
				<p class="khseo-big">—</p>
				<p><span class="khseo-label">NOT TESTED</span> <?php esc_html_e( 'Run an audit to get a score.', 'khseo' ); ?></p>
			<?php else : ?>
				<p class="khseo-big"><?php echo esc_html( $khseo_score . '/100' ); ?></p>
				<p class="khseo-meta"><?php echo esc_html( ScoreCalculator::DISCLAIMER ); ?></p>
				<?php if ( ! empty( $khseo_last['score']['capped'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Capped at 49 because a P0 (critical) check failed.', 'khseo' ); ?></strong></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<section class="khseo-card" aria-labelledby="khseo-open-h">
			<h2 id="khseo-open-h"><?php esc_html_e( 'Open issues', 'khseo' ); ?></h2>
			<ul class="khseo-counts">
				<?php foreach ( array( 'P0', 'P1', 'P2', 'P3' ) as $khseo_sev ) : ?>
					<li><a href="<?php echo esc_url( $khseo_issue( 'severity', $khseo_sev ) ); ?>"><span class="khseo-sev khseo-<?php echo esc_attr( strtolower( $khseo_sev ) ); ?>"><?php echo esc_html( $khseo_sev ); ?></span> <?php echo esc_html( (string) (int) ( $khseo_open[ $khseo_sev ] ?? 0 ) ); ?></a></li>
				<?php endforeach; ?>
				<li><a href="<?php echo esc_url( $khseo_issue( 'status', 'unknown' ) ); ?>"><span class="khseo-label">UNKNOWN</span> <?php echo esc_html( (string) (int) ( $khseo_open['unknown'] ?? 0 ) ); ?></a></li>
			</ul>
		</section>

		<section class="khseo-card" aria-labelledby="khseo-cov-h">
			<h2 id="khseo-cov-h"><?php esc_html_e( 'Coverage of the last audit', 'khseo' ); ?></h2>
			<?php if ( null === $khseo_last ) : ?>
				<p><span class="khseo-label">NOT TESTED</span> <?php esc_html_e( 'No audit yet.', 'khseo' ); ?></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Scope:', 'khseo' ); ?></strong> <?php echo esc_html( (string) $khseo_last['scope'] ); ?></p>
				<p class="khseo-meta"><?php esc_html_e( 'The score and issues apply to this scope only.', 'khseo' ); ?></p>
				<ul>
					<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'URLs analysed: %d', 'khseo' ), (int) $khseo_cov['urls_analysed'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'URLs with at least one failed check: %d', 'khseo' ), (int) $khseo_cov['urls_with_failures'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'URLs that could not be fetched: %d', 'khseo' ), (int) $khseo_cov['urls_fetch_failed'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Rules evaluated: %d', 'khseo' ), (int) $khseo_cov['rules_evaluated'] ) ); ?></li>
					<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Rules not tested: %d', 'khseo' ), count( (array) $khseo_cov['rules_not_tested'] ) ) ); ?></li>
					<li><span class="khseo-label">NOT TESTED</span> <?php esc_html_e( 'JavaScript rendering, Google index status, Google Rich Results Test.', 'khseo' ); ?></li>
				</ul>
				<p class="khseo-meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: date and time */
							__( 'Finished %s.', 'khseo' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $khseo_last['finished'] )
						)
					);
					?>
				</p>
				<?php foreach ( (array) $khseo_last['notes'] as $khseo_note ) : ?>
					<p class="khseo-meta"><?php echo esc_html( (string) $khseo_note ); ?></p>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
	</div>

	<?php if ( null !== $khseo_last ) : ?>
		<h2><?php esc_html_e( 'Score by category', 'khseo' ); ?></h2>
		<table class="widefat striped khseo-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Category', 'khseo' ); ?></th><th scope="col"><?php esc_html_e( 'Score', 'khseo' ); ?></th><th scope="col"><?php esc_html_e( 'Checks evaluated', 'khseo' ); ?></th><th scope="col"><?php esc_html_e( 'Failed', 'khseo' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( (array) $khseo_last['score']['categories'] as $khseo_cat => $khseo_c ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $khseo_issue( 'category', (string) $khseo_cat ) ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $khseo_cat ) ) ); ?></a></td>
					<td><?php echo null === $khseo_c['score'] ? '<span class="khseo-label">NOT TESTED</span>' : esc_html( $khseo_c['score'] . '/100' ); ?></td>
					<td><?php echo esc_html( (string) (int) $khseo_c['evaluated'] ); ?></td>
					<td><?php echo esc_html( (string) (int) $khseo_c['failed'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Audit', 'khseo' ); ?></h2>
	<?php if ( null !== $khseo_job && 'running' === $khseo_job['status'] ) : ?>
		<p id="khseo-progress" role="status" aria-live="polite">
			<?php echo esc_html( sprintf( /* translators: 1: processed, 2: remaining */ __( 'Audit running: %1$d URL(s) analysed, %2$d remaining.', 'khseo' ), (int) $khseo_job['processed'], (int) $khseo_job['remaining'] ) ); ?>
		</p>
		<?php if ( $can_audit ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="khseo-inline">
				<input type="hidden" name="action" value="khseo_audit_step"><?php wp_nonce_field( 'khseo_audit_step' ); ?>
				<?php submit_button( __( 'Process next batch', 'khseo' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="khseo-inline">
				<input type="hidden" name="action" value="khseo_audit_cancel"><?php wp_nonce_field( 'khseo_audit_cancel' ); ?>
				<?php submit_button( __( 'Cancel audit', 'khseo' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
	<?php elseif ( $can_audit ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="khseo_audit_start"><?php wp_nonce_field( 'khseo_audit_start' ); ?>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Audit scope', 'khseo' ); ?></legend>
				<label><input type="radio" name="scope" value="homepage" checked> <?php esc_html_e( 'Homepage', 'khseo' ); ?></label><br>
				<label><input type="radio" name="scope" value="sitemap"> <?php esc_html_e( 'Pages from the XML sitemap (limited by Settings → Audit limits)', 'khseo' ); ?></label><br>
				<label><input type="radio" name="scope" value="url"> <?php esc_html_e( 'One URL on this site:', 'khseo' ); ?></label>
				<label for="khseo-audit-url" class="screen-reader-text"><?php esc_html_e( 'URL to audit', 'khseo' ); ?></label>
				<input type="url" id="khseo-audit-url" name="url" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
			</fieldset>
			<?php submit_button( __( 'Run audit', 'khseo' ) ); ?>
		</form>
	<?php else : ?>
		<p><?php esc_html_e( 'You can view results; running audits needs the run_khseo_audit capability.', 'khseo' ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Site settings checks', 'khseo' ); ?></h2>
	<ul class="khseo-list">
		<?php foreach ( (array) $report['findings'] as $khseo_f ) : ?>
			<li><span class="khseo-label"><?php echo esc_html( $khseo_f['passed'] ? 'PASS' : 'FAIL' ); ?></span> <span class="khseo-label"><?php echo esc_html( (string) $khseo_f['evidence'] ); ?></span> <?php echo esc_html( $khseo_f['rule'] . ' — ' . $khseo_f['observed'] ); ?></li>
		<?php endforeach; ?>
	</ul>

	<h2><?php esc_html_e( 'Integrations and providers', 'khseo' ); ?></h2>
	<ul class="khseo-list">
		<li><strong><?php esc_html_e( 'Other SEO plugin:', 'khseo' ); ?></strong> <?php echo esc_html( (string) $report['seo_provider']['label'] ); ?> — <?php esc_html_e( 'KHSEO metadata output is PLANNED; KHSEO does not change titles, descriptions, canonicals, robots, OG or schema.', 'khseo' ); ?></li>
		<li><span class="khseo-label"><?php echo esc_html( (string) $report['search_console']['status'] ); ?></span> <?php echo esc_html( __( 'Clicks, impressions, CTR, position', 'khseo' ) . ' — ' . $report['search_console']['reason'] ); ?></li>
		<li><strong><?php esc_html_e( 'AI:', 'khseo' ); ?></strong> <?php echo esc_html( (string) $report['ai']['message'] ); ?></li>
		<li><strong><?php esc_html_e( 'Encryption key:', 'khseo' ); ?></strong> <?php echo esc_html( $report['encryption']['key_source'] . ' (' . $report['encryption']['strength'] . ')' ); ?></li>
	</ul>
</div>
