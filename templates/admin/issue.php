<?php
/**
 * One issue: what is wrong, why, evidence, recommendation, and a governed fix if one exists.
 *
 * @package KHSEO
 * @var array<string, mixed>|null              $issue
 * @var \KHSEO\Rules\Rule|null                  $rule
 * @var array<string, mixed>|null              $proposal
 * @var bool                                    $can_fix
 * @var bool                                    $can_edit
 * @var array<string, array<string, mixed>>     $journal
 * @var string                                  $notice
 */

declare(strict_types=1);

use KHSEO\Admin\AuditScreens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$khseo_back = add_query_arg( 'page', AuditScreens::ISSUES_SLUG, admin_url( 'admin.php' ) );
$khseo_form = static function ( string $action, array $fields, string $label, string $style = 'primary' ): void {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="khseo-inline">';
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	wp_nonce_field( $action );
	foreach ( $fields as $name => $value ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}
	submit_button( $label, $style, 'submit', false );
	echo '</form> ';
};
?>
<div class="wrap khseo">
	<p><a href="<?php echo esc_url( $khseo_back ); ?>">&larr; <?php esc_html_e( 'All issues', 'khseo' ); ?></a></p>
	<?php if ( '' !== $notice ) : ?>
		<div class="notice notice-info" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( null === $issue ) : ?>
		<h1><?php esc_html_e( 'Issue not found', 'khseo' ); ?></h1>
	<?php else : ?>
		<h1><?php echo esc_html( $issue['rule_id'] . ' — ' . $issue['message'] ); ?></h1>
		<table class="form-table khseo-detail" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'What is wrong', 'khseo' ); ?></th><td><?php echo esc_html( $issue['evidence_detail'] ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Evidence', 'khseo' ); ?></th><td><span class="khseo-label"><?php echo esc_html( $issue['evidence'] ); ?></span> <?php echo esc_html( __( 'Source:', 'khseo' ) . ' ' . $issue['source'] ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Affected URL', 'khseo' ); ?></th><td class="khseo-url"><?php echo esc_html( 'site' === $issue['url'] ? __( 'Site settings', 'khseo' ) : $issue['url'] ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Priority / risk', 'khseo' ); ?></th><td><?php echo esc_html( $issue['severity'] . ' / ' . $issue['fix_risk'] ); ?> <span class="khseo-meta"><?php esc_html_e( '(priority is urgency; risk decides what a change needs)', 'khseo' ); ?></span></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Recommendation', 'khseo' ); ?></th><td><?php echo esc_html( $issue['recommendation'] ); ?></td></tr>
			<?php if ( null !== $rule && '' !== $rule->docs ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Reference', 'khseo' ); ?></th><td><a href="<?php echo esc_url( $rule->docs ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $rule->docs ); ?></a></td></tr>
			<?php endif; ?>
			<tr><th scope="row"><?php esc_html_e( 'Status', 'khseo' ); ?></th><td><?php echo esc_html( $issue['status'] . ' · ' . __( 'last seen', 'khseo' ) . ' ' . $issue['updated_at'] . ' UTC' ); ?></td></tr>
		</table>

		<?php if ( $can_edit && in_array( $issue['status'], array( 'open', 'unknown', 'ignored' ), true ) ) : ?>
			<?php
			$khseo_form(
				'khseo_issue_status',
				array(
					'issue'  => $issue['id'],
					'status' => 'ignored' === $issue['status'] ? 'open' : 'ignored',
				),
				'ignored' === $issue['status'] ? __( 'Re-open issue', 'khseo' ) : __( 'Ignore issue', 'khseo' ),
				'secondary'
			);
			?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Fix', 'khseo' ); ?></h2>
		<?php if ( null === $proposal ) : ?>
			<p><?php esc_html_e( 'No automatic fix is available for this issue in this version (or the site no longer needs it). Follow the recommendation above.', 'khseo' ); ?></p>
		<?php else : ?>
			<table class="widefat khseo-table khseo-diff">
				<tbody>
					<tr><th scope="row"><?php esc_html_e( 'Change', 'khseo' ); ?></th><td><?php echo esc_html( $proposal['title'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Risk', 'khseo' ); ?></th><td><?php echo esc_html( $proposal['risk'] . ' — ' . $proposal['requires'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Target', 'khseo' ); ?></th><td><code><?php echo esc_html( $proposal['target'] ); ?></code></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Before', 'khseo' ); ?></th><td><code><?php echo esc_html( $proposal['before'] ); ?></code></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'After', 'khseo' ); ?></th><td><code><?php echo esc_html( $proposal['after'] ); ?></code></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Affected', 'khseo' ); ?></th><td><?php echo esc_html( implode( ', ', $proposal['affected'] ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Rollback', 'khseo' ); ?></th><td><?php echo esc_html( $proposal['rollback'] ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Validation', 'khseo' ); ?></th><td><?php echo esc_html( $proposal['validation'] ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( $can_fix ) : ?>
				<p class="khseo-meta"><?php esc_html_e( 'Step 1 approves this exact change (valid one hour). Step 2 applies it through the change gate, records a recovery point first, and validates the result.', 'khseo' ); ?></p>
				<?php
				$khseo_form(
					'khseo_fix_approve',
					array(
						'change_id' => $proposal['change_id'],
						'issue'     => $issue['id'],
					),
					__( '1. Approve this change', 'khseo' ),
					'secondary'
				);
				$khseo_form(
					'khseo_fix_apply',
					array(
						'change_id' => $proposal['change_id'],
						'issue'     => $issue['id'],
					),
					__( '2. Apply approved change', 'khseo' )
				);
				?>
			<?php else : ?>
				<p><?php esc_html_e( 'Applying fixes needs the manage_khseo and manage_options capabilities.', 'khseo' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		$khseo_entries = array_filter( $journal, static fn ( array $e ): bool => ( $e['rule_id'] ?? '' ) === $issue['rule_id'] );
		?>
		<?php if ( array() !== $khseo_entries ) : ?>
			<h2><?php esc_html_e( 'Change journal', 'khseo' ); ?></h2>
			<table class="widefat striped khseo-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'When', 'khseo' ); ?></th><th scope="col"><?php esc_html_e( 'Change', 'khseo' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'khseo' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'khseo' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $khseo_entries as $khseo_jid => $khseo_e ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $khseo_e['time'] ) ); ?></td>
						<td><code><?php echo esc_html( $khseo_e['target'] . ': ' . $khseo_e['before'] . ' → ' . $khseo_e['after'] ); ?></code></td>
						<td><?php echo esc_html( (string) $khseo_e['status'] ); ?></td>
						<td>
							<?php
							if ( 'applied' === $khseo_e['status'] && current_user_can( 'rollback_khseo_changes' ) && current_user_can( 'manage_options' ) ) {
								$khseo_form(
									'khseo_fix_rollback',
									array(
										'journal_id' => $khseo_jid,
										'issue'      => $issue['id'],
									),
									__( 'Roll back', 'khseo' ),
									'secondary'
								);
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>
</div>
