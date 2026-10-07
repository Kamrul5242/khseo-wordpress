<?php
/**
 * Overview screen. Answers: what is wrong, why it matters, what to do, and what is not tested yet.
 *
 * @package KHSEO
 * @var array<string, mixed> $report
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$khseo_problems = array_filter( $report['findings'], static fn ( array $f ): bool => ! $f['passed'] );
$khseo_passed   = array_filter( $report['findings'], static fn ( array $f ): bool => (bool) $f['passed'] );
?>
<div class="wrap khseo">
	<h1><?php esc_html_e( 'KHSEO Overview', 'khseo' ); ?></h1>
	<p class="khseo-lede">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: plugin version. */
				__( 'Version %s. Every statement below says how it is known: VERIFIED, UNKNOWN or NOT TESTED.', 'khseo' ),
				$report['version']
			)
		);
		?>
	</p>

	<h2><?php esc_html_e( 'What needs attention', 'khseo' ); ?></h2>
	<?php if ( array() === $khseo_problems ) : ?>
		<p><?php esc_html_e( 'No problems found in the site-level checks that ran.', 'khseo' ); ?></p>
	<?php else : ?>
		<table class="widefat striped khseo-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Issue', 'khseo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Evidence', 'khseo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recommended action', 'khseo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Priority / risk', 'khseo' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $khseo_problems as $khseo_f ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $khseo_f['rule'] ); ?></strong><br><?php echo esc_html( $khseo_f['observed'] ); ?></td>
					<td><span class="khseo-label"><?php echo esc_html( $khseo_f['evidence'] ); ?></span><br><small><?php echo esc_html( $khseo_f['source'] ); ?></small></td>
					<td><?php echo esc_html( $khseo_f['recommendation'] ); ?></td>
					<td><?php echo esc_html( $khseo_f['severity'] . ' / ' . $khseo_f['risk'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Checks that passed', 'khseo' ); ?></h2>
	<ul class="khseo-list">
		<?php foreach ( $khseo_passed as $khseo_f ) : ?>
			<li><span class="khseo-label"><?php echo esc_html( $khseo_f['evidence'] ); ?></span> <?php echo esc_html( $khseo_f['rule'] . ' — ' . $khseo_f['observed'] ); ?></li>
		<?php endforeach; ?>
	</ul>

	<h2><?php esc_html_e( 'Not tested or unknown', 'khseo' ); ?></h2>
	<ul class="khseo-list">
		<li><span class="khseo-label"><?php echo esc_html( $report['content_audit']['status'] ); ?></span> <?php echo esc_html( __( 'Page-level SEO audit', 'khseo' ) . ' — ' . $report['content_audit']['reason'] ); ?></li>
		<li><span class="khseo-label"><?php echo esc_html( $report['search_console']['status'] ); ?></span> <?php echo esc_html( __( 'Clicks, impressions, CTR, position', 'khseo' ) . ' — ' . $report['search_console']['reason'] ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'AI', 'khseo' ); ?></h2>
	<p><?php echo esc_html( $report['ai']['message'] ); ?></p>

	<p class="khseo-meta">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of rules. */
				_n( '%d SEO rule registered.', '%d SEO rules registered.', (int) $report['rules_registered'], 'khseo' ),
				(int) $report['rules_registered']
			)
		);
		?>
	</p>
</div>
