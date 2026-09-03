<?php
/**
 * Main connector behaviour: pairing, the outbound poll loop, and command execution.
 *
 * @package Plugsent_Connector
 */

if (! defined('ABSPATH')) {
    exit;
}

class Plugsent_Connector {

	const OPTION_SERVER  = 'plugsent_server_url';
	const OPTION_KEY     = 'plugsent_site_key';
	const OPTION_SECRET  = 'plugsent_site_secret';
	const OPTION_STATUS  = 'plugsent_status';
	const OPTION_LAST_SYNC = 'plugsent_last_sync';
	const CRON_HOOK      = 'plugsent_connector_tick';
	const PAGE_SLUG      = 'plugsent-connector';

	public static function init() {
		// Register the custom cron interval at plugin load (not on `init`) so it
		// is available during the activation request that schedules the tick.
		self::register_cron_interval();

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_plugsent_pair', array( __CLASS__, 'handle_pair' ) );
		add_action( 'admin_post_plugsent_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_plugsent_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * Brand stylesheet (Google Sans + header styles), only on the settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'plugsent-connector-admin',
			plugins_url( 'assets/plugsent-admin.css', PLUGSENT_CONNECTOR_FILE ),
			array(),
			PLUGSENT_CONNECTOR_VERSION
		);
	}

	public static function load_textdomain() {
		load_plugin_textdomain(
			'plugsent-connector',
			false,
			dirname( plugin_basename( PLUGSENT_CONNECTOR_FILE ) ) . '/languages'
		);
	}

	public static function register_cron_interval() {
		add_filter(
			'cron_schedules',
			function ( $schedules ) {
				if ( ! isset( $schedules['plugsent_minute'] ) ) {
					$schedules['plugsent_minute'] = array(
						'interval' => 60,
						'display'  => __( 'Every minute (Plugsent Connector)', 'plugsent-connector' ),
					);
				}
				return $schedules;
			}
		);
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'plugsent_minute', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function is_connected() {
		return get_option( self::OPTION_STATUS ) === 'connected'
			&& get_option( self::OPTION_KEY )
			&& get_option( self::OPTION_SECRET );
	}

	public static function register_menu() {
		add_options_page(
			__( 'Plugsent Connector', 'plugsent-connector' ),
			__( 'Plugsent Connector', 'plugsent-connector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connected = self::is_connected();
		$notice    = isset( $_GET['plugsent_msg'] ) ? sanitize_key( wp_unslash( $_GET['plugsent_msg'] ) ) : '';
		?>
		<div class="wrap">
			<div class="plugsent-header">
				<svg class="plugsent-mark" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
					<defs>
						<linearGradient id="plugsent-mark-bg" x1="0" y1="0" x2="1" y2="1">
							<stop offset="0" stop-color="#818CF8"/>
							<stop offset="1" stop-color="#4338CA"/>
						</linearGradient>
					</defs>
					<rect width="64" height="64" rx="14.5" fill="url(#plugsent-mark-bg)"/>
					<g fill="#FFFFFF">
						<rect x="24.5" y="11" width="6.5" height="14" rx="3.25"/>
						<rect x="33" y="11" width="6.5" height="14" rx="3.25"/>
						<path d="M19 23.5 h26 v13.5 a13 13 0 0 1 -13 13 a13 13 0 0 1 -13 -13 Z"/>
					</g>
					<path d="M32 50 v3.5 c0 3 -2.2 4.5 -4.8 4.5 h-4.4 c-2.2 0 -3.8 1.3 -3.8 3"
						  fill="none" stroke="#FFFFFF" stroke-width="4.5" stroke-linecap="round"/>
				</svg>
				<div>
					<h1><?php esc_html_e( 'Plugsent Connector', 'plugsent-connector' ); ?></h1>
					<p><?php esc_html_e( 'Connects this site to your Plugsent control plane — outbound-only, signed, and revocable.', 'plugsent-connector' ); ?></p>
				</div>
			</div>

			<?php if ( 'paired' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Paired successfully. The site will check in every minute.', 'plugsent-connector' ); ?></p></div>
			<?php elseif ( 'synced' === $notice ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Sync completed.', 'plugsent-connector' ); ?></p></div>
			<?php elseif ( 'bad_code' === $notice ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Pairing failed: the code is invalid, expired, or already used.', 'plugsent-connector' ); ?></p></div>
			<?php elseif ( 'unreachable' === $notice ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Pairing failed: could not reach the Plugsent server. Check the Server URL.', 'plugsent-connector' ); ?></p></div>
			<?php elseif ( 'disconnected' === $notice ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Disconnected from Plugsent.', 'plugsent-connector' ); ?></p></div>
			<?php elseif ( 'revoked' === $notice ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Plugsent revoked access for this site. Pair again to reconnect.', 'plugsent-connector' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $connected ) : ?>
				<p>
					<em><?php esc_html_e( 'Connected.', 'plugsent-connector' ); ?></em>
					<?php $last = get_option( self::OPTION_LAST_SYNC ); ?>
					<?php if ( $last ) : ?>
						<?php esc_html_e( 'Last check-in:', 'plugsent-connector' ); ?>
						<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) ); ?>
					<?php endif; ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="plugsent_sync" />
					<?php wp_nonce_field( 'plugsent_sync' ); ?>
					<?php submit_button( __( 'Sync now', 'plugsent-connector' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm('<?php esc_attr_e( 'Disconnect this site from Plugsent?', 'plugsent-connector' ); ?>');">
					<input type="hidden" name="action" value="plugsent_disconnect" />
					<?php wp_nonce_field( 'plugsent_disconnect' ); ?>
					<?php submit_button( __( 'Disconnect', 'plugsent-connector' ), 'delete', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Paste the credentials shown in your Plugsent dashboard under “Connect site”.', 'plugsent-connector' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="plugsent_pair" />
					<?php wp_nonce_field( 'plugsent_pair' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="plugsent_server"><?php esc_html_e( 'Plugsent server URL', 'plugsent-connector' ); ?></label></th>
							<td><input type="url" id="plugsent_server" name="plugsent_server" class="regular-text code" required
								placeholder="https://plugsent.example.com"
								value="<?php echo esc_attr( (string) get_option( self::OPTION_SERVER ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="plugsent_code"><?php esc_html_e( 'Pairing code', 'plugsent-connector' ); ?></label></th>
							<td><input type="text" id="plugsent_code" name="plugsent_code" class="regular-text code" required
								placeholder="PLSG-XXXXXXXXXXXX" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Pair with Plugsent', 'plugsent-connector' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_pair() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}
		check_admin_referer( 'plugsent_pair' );

		$server = untrailingslashit( esc_url_raw( wp_unslash( $_POST['plugsent_server'] ?? '' ) ) );
		$code   = sanitize_text_field( wp_unslash( $_POST['plugsent_code'] ?? '' ) );

		if ( empty( $server ) || empty( $code ) ) {
			self::redirect( 'bad_code' );
		}
		update_option( self::OPTION_SERVER, $server );

		$body = array(
			'code'         => $code,
			'site_url'     => home_url(),
			'name'         => get_bloginfo( 'name' ),
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'capabilities' => array( 'inventory.get', 'update.run' ),
		);

		$response = self::http_post( $server . '/connector/v1/pair', $body );

		if ( is_wp_error( $response ) ) {
			self::redirect( 'unreachable' );
		}

		$code_status = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $code_status || empty( $payload['site_key'] ) || empty( $payload['site_secret'] ) ) {
			self::redirect( 'bad_code' );
		}

		update_option( self::OPTION_KEY, sanitize_text_field( $payload['site_key'] ) );
		update_option( self::OPTION_SECRET, sanitize_text_field( $payload['site_secret'] ) );
		update_option( self::OPTION_STATUS, 'connected' );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'plugsent_minute', self::CRON_HOOK );
		}
		wp_schedule_single_event( time(), self::CRON_HOOK );

		self::redirect( 'paired' );
	}

	public static function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}
		check_admin_referer( 'plugsent_sync' );

		self::tick();
		self::redirect( 'synced' );
	}

	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}
		check_admin_referer( 'plugsent_disconnect' );

		self::forget_credentials();
		self::redirect( 'disconnected' );
	}

	/**
	 * One outbound cycle: poll for commands, execute them, report results.
	 */
	public static function tick() {
		if ( ! self::is_connected() ) {
			return;
		}

		$response = self::signed_request(
			'poll',
			array(
				'wp_version'   => get_bloginfo( 'version' ),
				'php_version'  => PHP_VERSION,
				'capabilities' => array( 'inventory.get', 'update.run' ),
				'health'       => array( 'status' => 'ok' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status ) {
			// The platform revoked us; drop credentials and stop checking in.
			self::forget_credentials();
			update_option( self::OPTION_STATUS, 'revoked' );
			return;
		}

		if ( 200 !== $status ) {
			return;
		}

		update_option( self::OPTION_LAST_SYNC, time() );

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$results = array();

		foreach ( (array) ( $payload['commands'] ?? array() ) as $command ) {
			$id   = isset( $command['id'] ) ? (int) $command['id'] : 0;
			$type = isset( $command['type'] ) ? sanitize_key( $command['type'] ) : '';

			if ( $id < 1 ) {
				continue;
			}

			if ( 'inventory.get' === $type ) {
				try {
					$results[] = array(
						'id'     => $id,
						'status' => 'ok',
						'data'   => array( 'inventory' => self::command_inventory_get() ),
					);
				} catch ( Exception $e ) {
					$results[] = array( 'id' => $id, 'status' => 'failed', 'error' => $e->getMessage() );
				}
				continue;
			}

			if ( 'update.run' === $type ) {
				$payload = isset( $command['payload'] ) && is_array( $command['payload'] ) ? $command['payload'] : array();
				try {
					$results[] = array(
						'id'     => $id,
						'status' => 'ok',
						'data'   => array( 'update' => self::command_update_run( $payload ) ),
					);
				} catch ( Exception $e ) {
					$results[] = array( 'id' => $id, 'status' => 'failed', 'error' => $e->getMessage() );
				}
				continue;
			}

			$results[] = array( 'id' => $id, 'status' => 'failed', 'error' => 'unsupported_command' );
		}

		if ( ! empty( $results ) ) {
			self::signed_request( 'results', array( 'results' => $results ) );
		}
	}

	/**
	 * Update a single plugin, theme, or WordPress core.
	 *
	 * @param array $payload {context: plugin|theme|core, slug: string}
	 * @return array{context: string, slug: string, ok: bool, message: string, version: string|null}
	 */
	private static function command_update_run( $payload ) {
		$context = isset( $payload['context'] ) ? sanitize_key( $payload['context'] ) : '';
		$slug    = isset( $payload['slug'] ) ? sanitize_text_field( $payload['slug'] ) : '';

		if (! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if (! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if (! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		if ( 'plugin' === $context ) {
			$target = null;
			$before = null;
			foreach ( (array) get_plugins() as $file => $info ) {
				$candidate = dirname( $file ) !== '.' ? dirname( $file ) : sanitize_title( $info['Name'] );
				if ( $candidate === $slug ) {
					$target = $file;
					$before = $info['Version'];
					break;
				}
			}
			if ( null === $target ) {
				return array( 'context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Plugin not found.', 'version' => null );
			}
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $target );
			$plugins  = (array) get_plugins();
			$after    = isset( $plugins[ $target ]['Version'] ) ? $plugins[ $target ]['Version'] : null;
			if ( is_wp_error( $result ) ) {
				return array( 'context' => $context, 'slug' => $slug, 'ok' => false, 'message' => $result->get_error_message(), 'version' => $after );
			}
			$changed = ( null !== $after && $after !== $before );
			return array( 'context' => $context, 'slug' => $slug, 'ok' => true, 'message' => $changed ? 'Updated.' : 'No update was applied (possibly already up to date).', 'version' => $after );
		}

		if ( 'theme' === $context ) {
			if ( null === wp_get_theme( $slug )->get( 'Version' ) && ! wp_get_theme( $slug )->exists() ) {
				return array( 'context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Theme not found.', 'version' => null );
			}
			$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $slug );
			$after    = wp_get_theme( $slug )->get( 'Version' );
			if ( is_wp_error( $result ) ) {
				return array( 'context' => $context, 'slug' => $slug, 'ok' => false, 'message' => $result->get_error_message(), 'version' => $after );
			}
			return array( 'context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Updated.', 'version' => $after );
		}

		if ( 'core' === $context ) {
			$before = get_bloginfo( 'version' );
			$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade();
			if ( is_wp_error( $result ) ) {
				return array( 'context' => $context, 'slug' => 'wordpress', 'ok' => false, 'message' => $result->get_error_message(), 'version' => $before );
			}
			return array( 'context' => $context, 'slug' => 'wordpress', 'ok' => true, 'message' => 'Core update processed.', 'version' => get_bloginfo( 'version' ) );
		}

		return array( 'context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Unknown update context.', 'version' => null );
	}

	/**
	 * @return array{core: array, plugins: array, themes: array}
	 */
	private static function command_inventory_get() {
		if (! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if (! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugin_updates = (array) get_plugin_updates();

		$plugins = array();
		foreach ( (array) get_plugins() as $file => $plugin ) {
			$update_version = null;
			if ( isset( $plugin_updates[ $file ]->update->new_version ) ) {
				$update_version = $plugin_updates[ $file ]->update->new_version;
			}
			$plugins[] = array(
				'slug'             => dirname( $file ) !== '.' ? dirname( $file ) : sanitize_title( $plugin['Name'] ),
				'name'             => $plugin['Name'],
				'version'          => $plugin['Version'],
				'update_available' => $update_version !== null,
				'update_version'   => $update_version,
				'active'           => in_array( $file, $active_plugins, true ),
			);
		}

		$themes       = array();
		$theme_updates = (array) get_theme_updates();
		$active_theme = get_stylesheet();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$update_version = null;
			$update_file    = $slug . '/style.css';
			if ( isset( $theme_updates[ $update_file ]->update->new_version ) ) {
				$update_version = $theme_updates[ $update_file ]->update->new_version;
			}
			$themes[] = array(
				'slug'             => $slug,
				'name'             => $theme->get( 'Name' ),
				'version'          => $theme->get( 'Version' ),
				'update_available' => $update_version !== null,
				'update_version'   => $update_version,
				'active'           => ( $slug === $active_theme ),
			);
		}

		$core = array(
			'slug'             => 'wordpress',
			'name'             => 'WordPress',
			'version'          => get_bloginfo( 'version' ),
			'update_available' => false,
			'update_version'   => null,
			'active'           => true,
		);
		$core_updates = get_site_transient( 'update_core' );
		if ( ! empty( $core_updates->updates ) && is_array( $core_updates->updates ) ) {
			foreach ( $core_updates->updates as $update ) {
				if ( isset( $update->response ) && 'upgrade' === $update->response && ! empty( $update->version ) ) {
					$core['update_available'] = true;
					$core['update_version']   = $update->version;
					break;
				}
			}
		}

		return array(
			'core'    => array( $core ),
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	private static function signed_request( $path, $body_array ) {
		return self::http_post(
			get_option( self::OPTION_SERVER ) . '/connector/v1/' . $path,
			$body_array,
			true
		);
	}

	private static function http_post( $url, $body_array, $signed = false ) {
		$body = wp_json_encode( $body_array );
		$headers = array( 'Content-Type' => 'application/json' );

		if ( $signed ) {
			$timestamp = time();
			$headers['X-Plugsent-Key']       = (string) get_option( self::OPTION_KEY );
			$headers['X-Plugsent-Timestamp'] = (string) $timestamp;
			$headers['X-Plugsent-Nonce']     = wp_generate_uuid4();
			$headers['X-Plugsent-Signature'] = Plugsent_Connector_Signer::sign(
				(string) get_option( self::OPTION_SECRET ),
				$timestamp,
				(string) $body
			);
		}

		return wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			)
		);
	}

	private static function forget_credentials() {
		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_SECRET );
		delete_option( self::OPTION_LAST_SYNC );
		update_option( self::OPTION_STATUS, 'disconnected' );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	private static function redirect( $message ) {
		wp_safe_redirect( add_query_arg( 'plugsent_msg', $message, admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}
}
