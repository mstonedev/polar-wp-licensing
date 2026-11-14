<?php
/**
 * The license screen: a key field, Activate / Deactivate / Check buttons, and status.
 *
 * Either let the library add its own submenu page (the default), or set
 * 'menu_parent' => false and call render_form() inside your own settings page.
 *
 * @package PolarWP\Licensing
 */

namespace PolarWP\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminPage {

	const CAPABILITY = 'manage_options';

	private License $license;
	private string $plugin_file;
	private string $plugin_name;
	private ?string $menu_parent;
	private string $page_slug;
	private string $purchase_url;
	private string $portal_url;

	public function __construct( License $license, string $plugin_file, string $plugin_name, array $args = array() ) {
		$this->license      = $license;
		$this->plugin_file  = $plugin_file;
		$this->plugin_name  = $plugin_name;
		$this->menu_parent  = array_key_exists( 'menu_parent', $args ) && false === $args['menu_parent'] ? null : ( $args['menu_parent'] ?? 'options-general.php' );
		$this->page_slug    = $license->slug() . '-license';
		$this->purchase_url = (string) ( $args['purchase_url'] ?? '' );
		$this->portal_url   = (string) ( $args['portal_url'] ?? '' );
	}

	public function register(): void {
		add_action( 'admin_post_' . $this->action(), array( $this, 'handle_post' ) );

		if ( null !== $this->menu_parent ) {
			add_action( 'admin_menu', array( $this, 'add_menu' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), array( $this, 'action_link' ) );
		}
	}

	public function add_menu(): void {
		add_submenu_page(
			$this->menu_parent,
			/* translators: %s: plugin name */
			sprintf( __( '%s License', 'polarwp-licensing' ), $this->plugin_name ),
			/* translators: %s: plugin name */
			sprintf( __( '%s License', 'polarwp-licensing' ), $this->plugin_name ),
			self::CAPABILITY,
			$this->page_slug,
			array( $this, 'render_page' )
		);
	}

	public function action_link( array $links ): array {
		$url = add_query_arg( 'page', $this->page_slug, admin_url( $this->menu_parent ) );
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'License', 'polarwp-licensing' ) ) );
		return $links;
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Opening the screen is a good moment for a fresh (rate-limited) check.
		$this->license->maybe_check();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html( sprintf( __( '%s License', 'polarwp-licensing' ), $this->plugin_name ) ) );
		$this->render_form();
		echo '</div>';
	}

	/** Public so authors can embed the form in their own settings page. */
	public function render_form(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->render_notice();

		$state  = $this->license->state();
		$status = $this->license->status();
		$has    = '' !== $state['key'];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $this->action() ); ?>">
			<?php wp_nonce_field( $this->action() ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $this->action() ); ?>_key"><?php esc_html_e( 'License key', 'polarwp-licensing' ); ?></label>
					</th>
					<td>
						<?php if ( $has ) : ?>
							<code><?php echo esc_html( $state['display_key'] ?: License::mask( $state['key'] ) ); ?></code>
						<?php else : ?>
							<input type="text" class="regular-text code" autocomplete="off" spellcheck="false"
								id="<?php echo esc_attr( $this->action() ); ?>_key" name="license_key">
							<?php if ( $this->purchase_url ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to buy a license */
										esc_html__( "Don't have a key? %s", 'polarwp-licensing' ),
										sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $this->purchase_url ), esc_html__( 'Buy a license', 'polarwp-licensing' ) )
									);
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>

				<?php if ( $has ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'polarwp-licensing' ); ?></th>
						<td>
							<?php $this->render_status( $status, $state ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<p class="submit">
				<?php if ( $has ) : ?>
					<button type="submit" name="license_action" value="check" class="button"><?php esc_html_e( 'Check now', 'polarwp-licensing' ); ?></button>
					<button type="submit" name="license_action" value="deactivate" class="button button-secondary"><?php esc_html_e( 'Deactivate on this site', 'polarwp-licensing' ); ?></button>
				<?php else : ?>
					<button type="submit" name="license_action" value="activate" class="button button-primary"><?php esc_html_e( 'Activate', 'polarwp-licensing' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	private function render_status( string $status, array $state ): void {
		$ok = License::STATUS_VALID === $status;

		printf(
			'<strong style="color:%s">%s</strong>',
			esc_attr( $ok ? '#00a32a' : '#d63638' ),
			esc_html( $ok ? __( 'Active', 'polarwp-licensing' ) : $this->license->describe_status( $status ) )
		);

		$details = array();

		if ( $state['customer_email'] ) {
			/* translators: %s: customer email */
			$details[] = sprintf( __( 'Licensed to %s.', 'polarwp-licensing' ), $state['customer_email'] );
		}
		if ( $state['expires_at'] ) {
			$details[] = sprintf(
				/* translators: %s: date */
				$ok ? __( 'Renews or expires on %s.', 'polarwp-licensing' ) : __( 'Expired on %s.', 'polarwp-licensing' ),
				wp_date( get_option( 'date_format' ), (int) $state['expires_at'] )
			);
		}
		if ( $this->license->is_in_grace_period() ) {
			$details[] = __( "The license server couldn't be reached on the last check, so the last known status is being used.", 'polarwp-licensing' );
		} elseif ( ! $ok && $state['last_error'] ) {
			$details[] = $state['last_error'];
		}

		foreach ( $details as $line ) {
			printf( '<p class="description">%s</p>', esc_html( $line ) );
		}

		if ( $this->portal_url ) {
			printf(
				'<p class="description"><a href="%s" target="_blank" rel="noopener">%s</a></p>',
				esc_url( $this->portal_url ),
				esc_html__( 'Manage your license and sites in the customer portal', 'polarwp-licensing' )
			);
		}
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this license.', 'polarwp-licensing' ), 403 );
		}

		check_admin_referer( $this->action() );

		$action = isset( $_POST['license_action'] ) ? sanitize_key( wp_unslash( $_POST['license_action'] ) ) : '';

		switch ( $action ) {
			case 'activate':
				$key    = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
				$result = $this->license->activate( $key );
				break;
			case 'deactivate':
				$result = $this->license->deactivate();
				break;
			case 'check':
				$this->license->check();
				$status = $this->license->status();
				$result = array(
					'success' => License::STATUS_VALID === $status,
					'message' => License::STATUS_VALID === $status
						? __( 'License is active.', 'polarwp-licensing' )
						: $this->license->describe_status( $status ),
				);
				break;
			default:
				$result = array(
					'success' => false,
					'message' => __( 'Unknown action.', 'polarwp-licensing' ),
				);
		}

		set_transient( $this->notice_key(), $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	private function render_notice(): void {
		$notice = get_transient( $this->notice_key() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $this->notice_key() );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice['success'] ? 'success' : 'error' ),
			esc_html( $notice['message'] )
		);
	}

	private function action(): string {
		return 'polarwp_license_' . $this->license->slug();
	}

	private function notice_key(): string {
		return $this->action() . '_notice_' . get_current_user_id();
	}
}
