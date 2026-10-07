<?php
/**
 * Issues list with filters and pagination.
 *
 * @package KHSEO
 * @var array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int} $result
 * @var array<string, string> $filters
 */

declare(strict_types=1);

use KHSEO\Admin\AuditScreens;
use KHSEO\Findings\FindingRepository;
use KHSEO\Rules\Rule;
use KHSEO\Support\Evidence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$khseo_select = static function ( string $name, string $label, array $options, string $current ): void {
	printf( '<label for="khseo-f-%1$s" class="screen-reader-text">%2$s</label><select id="khseo-f-%1$s" name="%1$s"><option value="">%2$s: %3$s</option>', esc_attr( $name ), esc_html( $label ), esc_html__( 'all', 'khseo' ) );
	foreach ( $options as $value ) {
		printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $value ) );
	}
	echo '</select> ';
};
$khseo_pages  = (int) ceil( $result['total'] / max( 1, $result['per_page'] ) );
?>
<div class="wrap khseo">
	<h1><?php esc_html_e( 'KHSEO Issues', 'khseo' ); ?></h1>
	<p class="khseo-meta"><?php esc_html_e( 'Issues from audits of the scope shown on the Overview. By default only current problems (open and unknown) are listed.', 'khseo' ); ?></p>

	<form method="get" class="khseo-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( AuditScreens::ISSUES_SLUG ); ?>">
		<?php
		$khseo_select( 'severity', __( 'Severity', 'khseo' ), array( 'P0', 'P1', 'P2', 'P3' ), $filters['severity'] );
		$khseo_select( 'category', __( 'Category', 'khseo' ), Rule::CATEGORIES, $filters['category'] );
		$khseo_select( 'status', __( 'Status', 'khseo' ), FindingRepository::STATUSES, $filters['status'] );
		$khseo_select( 'evidence', __( 'Evidence', 'khseo' ), array_map( static fn ( Evidence $e ): string => $e->value, Evidence::cases() ), $filters['evidence'] );
		$khseo_select( 'fixable', __( 'Fix available', 'khseo' ), array( '1', '0' ), $filters['fixable'] );
		?>
		<label for="khseo-f-url" class="screen-reader-text"><?php esc_html_e( 'URL contains', 'khseo' ); ?></label>
		<input type="search" id="khseo-f-url" name="url" value="<?php echo esc_attr( $filters['url'] ); ?>" placeholder="<?php esc_attr_e( 'URL contains…', 'khseo' ); ?>">
		<?php submit_button( __( 'Filter', 'khseo' ), 'secondary', '', false ); ?>
	</form>

	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of issues */
				_n( '%d issue', '%d issues', $result['total'], 'khseo' ),
				$result['total']
			)
		);
		?>
	</p>

	<table class="widefat striped khseo-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Severity', 'khseo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Issue', 'khseo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'URL', 'khseo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Evidence', 'khseo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'khseo' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Fix', 'khseo' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( array() === $result['items'] ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No issues match. If no audit has run yet, start one on the Overview.', 'khseo' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['items'] as $khseo_i ) : ?>
			<tr>
				<td><span class="khseo-sev khseo-<?php echo esc_attr( strtolower( $khseo_i['severity'] ) ); ?>"><?php echo esc_html( $khseo_i['severity'] ); ?></span></td>
				<td>
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'  => AuditScreens::ISSUES_SLUG,
								'issue' => $khseo_i['id'],
							),
							admin_url( 'admin.php' )
						)
					);
					?>
								"><strong><?php echo esc_html( $khseo_i['rule_id'] ); ?></strong></a><br>
					<?php echo esc_html( $khseo_i['message'] ); ?>
				</td>
				<td class="khseo-url"><?php echo esc_html( 'site' === $khseo_i['url'] ? __( 'Site settings', 'khseo' ) : $khseo_i['url'] ); ?></td>
				<td><span class="khseo-label"><?php echo esc_html( $khseo_i['evidence'] ); ?></span></td>
				<td><?php echo esc_html( $khseo_i['status'] ); ?></td>
				<td><?php echo esc_html( $khseo_i['fix_available'] ? sprintf( /* translators: %s: risk level */ __( 'Available (%s)', 'khseo' ), $khseo_i['fix_risk'] ) : __( 'Manual', 'khseo' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $khseo_pages > 1 ) : ?>
		<nav class="khseo-pagination" aria-label="<?php esc_attr_e( 'Issue pages', 'khseo' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $result['page'],
						'total'   => $khseo_pages,
					)
				) ?? ''
			);
			?>
		</nav>
	<?php endif; ?>
</div>
