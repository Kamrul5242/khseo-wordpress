<?php
/**
 * Settings screen.
 *
 * @package KHSEO
 * @var array<string, mixed> $settings
 * @var string               $ai_key_mask
 * @var bool                 $can_manage_ai
 * @var bool                 $can_encrypt
 */

declare(strict_types=1);

use KHSEO\Admin\AdminModule;
use KHSEO\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$khseo_opt     = Settings::OPTION;
$khseo_key_msg = array(
	'saved'         => __( 'API key saved (encrypted).', 'khseo' ),
	'removed'       => __( 'API key removed.', 'khseo' ),
	'invalid'       => __( 'That API key was not saved: it is too long or contains spaces or control characters.', 'khseo' ),
	'no_encryption' => __( 'That API key was not saved: this site has no safe encryption key. Add a KHSEO_SECRET_KEY constant (a long random string) to wp-config.php.', 'khseo' ),
);
// Display-only flag set by our own redirect; compared against a fixed list.
$khseo_key_status = isset( $_GET['khseo_key'] ) ? sanitize_key( wp_unslash( $_GET['khseo_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.

/**
 * Render a checkbox with a hidden 0 so unticking actually saves "off".
 *
 * @param string $name  Setting key.
 * @param bool   $value Current value.
 * @param string $label Label.
 */
$khseo_checkbox = static function ( string $name, bool $value, string $label ) use ( $khseo_opt ): void {
	$id = 'khseo-' . $name;
	printf(
		'<input type="hidden" name="%1$s[%2$s]" value="0"><label for="%3$s"><input type="checkbox" id="%3$s" name="%1$s[%2$s]" value="1" %4$s> %5$s</label>',
		esc_attr( $khseo_opt ),
		esc_attr( $name ),
		esc_attr( $id ),
		checked( $value, true, false ),
		esc_html( $label )
	);
};

/**
 * Render a select.
 *
 * @param string             $name    Setting key.
 * @param string             $value   Current value.
 * @param array<int, string> $options Allowed values.
 */
$khseo_select = static function ( string $name, string $value, array $options ) use ( $khseo_opt ): void {
	printf( '<select id="khseo-%2$s" name="%1$s[%2$s]">', esc_attr( $khseo_opt ), esc_attr( $name ) );
	foreach ( $options as $option ) {
		printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $option ) );
	}
	echo '</select>';
};
?>
<div class="wrap khseo">
	<h1><?php esc_html_e( 'KHSEO Settings', 'khseo' ); ?></h1>
	<?php settings_errors(); ?>

	<?php if ( isset( $khseo_key_msg[ $khseo_key_status ] ) ) : ?>
		<div class="notice <?php echo in_array( $khseo_key_status, array( 'invalid', 'no_encryption' ), true ) ? 'notice-error' : 'notice-success'; ?>" role="status"><p><?php echo esc_html( $khseo_key_msg[ $khseo_key_status ] ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( Settings::OPTION ); ?>

		<h2><?php esc_html_e( 'General', 'khseo' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="khseo-compatibility_mode"><?php esc_html_e( 'Mode with other SEO plugins', 'khseo' ); ?></label></th>
				<td>
					<?php $khseo_select( 'compatibility_mode', (string) $settings['compatibility_mode'], Settings::COMPATIBILITY_MODES ); ?>
					<p class="description"><?php esc_html_e( 'Advisory: KHSEO reports and suggests but does not output metadata. Use Primary only when no other SEO plugin is active.', 'khseo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Automation', 'khseo' ); ?></th>
				<td>
					<?php $khseo_checkbox( 'safe_auto_fixes', (bool) $settings['safe_auto_fixes'], __( 'Allow safe, reversible (R1) fixes without asking each time', 'khseo' ) ); ?><br>
					<?php $khseo_checkbox( 'scheduled_jobs', (bool) $settings['scheduled_jobs'], __( 'Run scheduled health checks', 'khseo' ) ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="khseo-log_level"><?php esc_html_e( 'Log level', 'khseo' ); ?></label></th>
				<td>
					<?php $khseo_select( 'log_level', (string) $settings['log_level'], Settings::LOG_LEVELS ); ?>
					<label for="khseo-log_retention_days"><?php esc_html_e( 'Keep logs for (days)', 'khseo' ); ?></label>
					<input type="number" min="1" max="90" id="khseo-log_retention_days" name="<?php echo esc_attr( $khseo_opt ); ?>[log_retention_days]" value="<?php echo esc_attr( (string) $settings['log_retention_days'] ); ?>" class="small-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'khseo' ); ?></th>
				<td><?php $khseo_checkbox( 'delete_data_on_uninstall', (bool) $settings['delete_data_on_uninstall'], __( 'Delete all KHSEO data when the plugin is deleted', 'khseo' ) ); ?></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'AI (optional)', 'khseo' ); ?></h2>
		<p><?php esc_html_e( 'KHSEO works fully without AI. When enabled, only the minimum content needed for a task is sent, and only after you start that task.', 'khseo' ); ?></p>
		<p class="description"><?php esc_html_e( 'AI features are not built yet in this version. These settings are stored now and will be used when the AI layer arrives. Nothing is sent to any AI provider today.', 'khseo' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="khseo-ai_provider"><?php esc_html_e( 'Provider', 'khseo' ); ?></label></th>
				<td><?php $khseo_select( 'ai_provider', (string) $settings['ai_provider'], Settings::AI_PROVIDERS ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="khseo-ai_model"><?php esc_html_e( 'Model', 'khseo' ); ?></label></th>
				<td><input type="text" id="khseo-ai_model" name="<?php echo esc_attr( $khseo_opt ); ?>[ai_model]" value="<?php echo esc_attr( (string) $settings['ai_model'] ); ?>" class="regular-text" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row"><label for="khseo-ai_temperature"><?php esc_html_e( 'Temperature (0–1)', 'khseo' ); ?></label></th>
				<td><input type="number" step="0.05" min="0" max="1" id="khseo-ai_temperature" name="<?php echo esc_attr( $khseo_opt ); ?>[ai_temperature]" value="<?php echo esc_attr( (string) $settings['ai_temperature'] ); ?>" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="khseo-ai_max_output_tokens"><?php esc_html_e( 'Maximum output tokens', 'khseo' ); ?></label></th>
				<td><input type="number" min="64" max="8192" id="khseo-ai_max_output_tokens" name="<?php echo esc_attr( $khseo_opt ); ?>[ai_max_output_tokens]" value="<?php echo esc_attr( (string) $settings['ai_max_output_tokens'] ); ?>" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Privacy', 'khseo' ); ?></th>
				<td>
					<?php $khseo_checkbox( 'ai_privacy_mode', (bool) $settings['ai_privacy_mode'], __( 'Privacy mode: strip names, emails and URLs of users before sending', 'khseo' ) ); ?><br>
					<?php $khseo_checkbox( 'ai_send_content', (bool) $settings['ai_send_content'], __( 'Allow sending post content to the AI provider', 'khseo' ) ); ?>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<?php if ( $can_manage_ai ) : ?>
		<h2><?php esc_html_e( 'AI API key', 'khseo' ); ?></h2>
		<?php if ( ! $can_encrypt ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Encryption is unavailable on this site, so API keys cannot be saved. Add a KHSEO_SECRET_KEY constant (a long random string) to wp-config.php.', 'khseo' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( AdminModule::SECRET_ACTION ); ?>">
			<?php wp_nonce_field( AdminModule::SECRET_ACTION ); ?>
			<p>
				<?php if ( '' !== $ai_key_mask ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: masked key such as ••••9f2c. */
							__( 'Saved key: %s', 'khseo' ),
							$ai_key_mask
						)
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'No key saved.', 'khseo' ); ?>
				<?php endif; ?>
			</p>
			<label for="khseo-ai-key" class="screen-reader-text"><?php esc_html_e( 'New API key', 'khseo' ); ?></label>
			<input type="password" id="khseo-ai-key" name="khseo_ai_api_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Paste a new key to replace', 'khseo' ); ?>">
			<?php submit_button( __( 'Save key', 'khseo' ), 'secondary', 'submit', false ); ?>
			<?php if ( '' !== $ai_key_mask ) : ?>
				<?php submit_button( __( 'Remove key', 'khseo' ), 'delete', 'khseo_remove_key', false ); ?>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'The key is encrypted on the server and is never shown again, logged, exported or sent to the browser.', 'khseo' ); ?></p>
		</form>
	<?php endif; ?>
</div>
