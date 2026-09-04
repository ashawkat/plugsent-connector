<?php
/**
 * Main connector behaviour: pairing, the outbound poll loop, and command execution.
 */
if (! defined('ABSPATH')) {
    exit;
}

class Plugsent_Connector
{
    const OPTION_SERVER = 'plugsent_server_url';

    const OPTION_KEY = 'plugsent_site_key';

    const OPTION_SECRET = 'plugsent_site_secret';

    const OPTION_STATUS = 'plugsent_status';

    const OPTION_LAST_SYNC = 'plugsent_last_sync';
    const OPTION_ADMIN_USER = 'plugsent_admin_user';

    const CRON_HOOK = 'plugsent_connector_tick';

    const PAGE_SLUG = 'plugsent-connector';

    /**
     * Command ids whose result was already reported during this request.
     * The fatal shutdown handler uses this to avoid double-reporting.
     *
     * @var array<int, bool>
     */
    private static $answered_commands = [];

    /**
     * Post one command result and remember it was answered.
     */
    private static function post_result($id, $status, ?array $data = null, ?string $error = null)
    {
        $result = ['id' => $id, 'status' => $status];

        if ($data !== null) {
            $result['data'] = $data;
        }
        if ($error !== null) {
            $result['error'] = $error;
        }

        self::signed_request('results', ['results' => [$result]]);
        self::$answered_commands[$id] = true;
    }

    /**
     * Register a shutdown handler that reports a delivered command as
     * failed if PHP dies mid-execution (an undefined admin include on a
     * WP-Cron request, a memory limit, ...). Without this, a fatal would
     * leave the command wedged in the platform as eternally running.
     */
    private static function guard_against_fatal($id)
    {
        register_shutdown_function(static function () use ($id): void {
            if (! empty(self::$answered_commands[$id])) {
                return;
            }

            $error = error_get_last();

            if (! is_array($error) || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            self::signed_request('results', ['results' => [[
                'id' => $id,
                'status' => 'failed',
                'error' => 'PHP fatal while running the command: '.$error['message'].' ('.basename((string) $error['file']).':'.$error['line'].')',
            ]]]);
        });
    }

    /**
     * Parse a connection string from the dashboard: "server::credential".
     *
     * @return array{0: string, 1: string}|null [server, credential] or null when invalid.
     */
    public static function parse_connection_string($raw)
    {
        $raw = trim((string) $raw);

        if ($raw === '' || ! str_contains($raw, '::')) {
            return null;
        }

        $parts = explode('::', $raw, 2);
        $server = rtrim(trim($parts[0]), '/');
        $credential = trim($parts[1]);

        if ($server === '' || $credential === '') {
            return null;
        }

        return [$server, $credential];
    }

    public static function init()
    {
        // Register the custom cron interval at plugin load (not on `init`) so it
        // is available during the activation request that schedules the tick.
        self::register_cron_interval();

        add_action('init', [__CLASS__, 'load_textdomain']);
        add_action(self::CRON_HOOK, [__CLASS__, 'tick']);
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('admin_post_plugsent_pair', [__CLASS__, 'handle_pair']);
        add_action('admin_post_plugsent_sync', [__CLASS__, 'handle_sync']);
        add_action('admin_post_plugsent_disconnect', [__CLASS__, 'handle_disconnect']);
        add_action('init', [__CLASS__, 'maybe_magic_login'], 1);
    }

    /**
     * Brand stylesheet (Google Sans + header styles), only on the settings page.
     *
     * @param  string  $hook_suffix  Current admin page hook.
     */
    public static function enqueue_admin_assets($hook_suffix)
    {
        if ('settings_page_'.self::PAGE_SLUG !== $hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'plugsent-connector-admin',
            plugins_url('assets/plugsent-admin.css', PLUGSENT_CONNECTOR_FILE),
            [],
            PLUGSENT_CONNECTOR_VERSION
        );
    }

    public static function load_textdomain()
    {
        load_plugin_textdomain(
            'plugsent-connector',
            false,
            dirname(plugin_basename(PLUGSENT_CONNECTOR_FILE)).'/languages'
        );
    }

    public static function register_cron_interval()
    {
        add_filter(
            'cron_schedules',
            function ($schedules) {
                if (! isset($schedules['plugsent_minute'])) {
                    $schedules['plugsent_minute'] = [
                        'interval' => 60,
                        'display' => __('Every minute (Plugsent Connector)', 'plugsent-connector'),
                    ];
                }

                return $schedules;
            }
        );
    }

    public static function activate()
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'plugsent_minute', self::CRON_HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function is_connected()
    {
        return get_option(self::OPTION_STATUS) === 'connected'
            && get_option(self::OPTION_KEY)
            && get_option(self::OPTION_SECRET);
    }

    public static function register_menu()
    {
        add_options_page(
            __('Plugsent Connector', 'plugsent-connector'),
            __('Plugsent Connector', 'plugsent-connector'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function render_settings_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $connected = self::is_connected();
        $notice = isset($_GET['plugsent_msg']) ? sanitize_key(wp_unslash($_GET['plugsent_msg'])) : '';
        ?>
		<div class="wrap plugsent-wrap">
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
				<div class="plugsent-header-text">
					<h1><?php esc_html_e('Plugsent Connector', 'plugsent-connector'); ?></h1>
					<p><?php esc_html_e('Connects this site to your Plugsent control plane — outbound-only, signed, and revocable.', 'plugsent-connector'); ?></p>
				</div>
				<span class="plugsent-pill plugsent-pill-<?php echo $connected ? 'on' : 'off'; ?>">
					<span class="plugsent-pill-dot"></span>
					<?php echo $connected ? esc_html__('Connected', 'plugsent-connector') : esc_html__('Not connected', 'plugsent-connector'); ?>
				</span>
			</div>

			<?php if ($notice === 'paired') { ?>
				<div class="notice notice-success plugsent-notice"><p><?php esc_html_e('Paired successfully. The site will check in every minute.', 'plugsent-connector'); ?></p></div>
			<?php } elseif ($notice === 'synced') { ?>
				<div class="notice notice-success plugsent-notice"><p><?php esc_html_e('Sync completed.', 'plugsent-connector'); ?></p></div>
			<?php } elseif ($notice === 'bad_code') { ?>
				<div class="notice notice-error plugsent-notice"><p><?php esc_html_e('Pairing failed: the code is invalid, expired, or already used.', 'plugsent-connector'); ?></p></div>
			<?php } elseif ($notice === 'unreachable') { ?>
				<div class="notice notice-error plugsent-notice"><p><?php esc_html_e('Pairing failed: could not reach the Plugsent server. Check the Server URL.', 'plugsent-connector'); ?></p></div>
			<?php } elseif ($notice === 'disconnected') { ?>
				<div class="notice notice-warning plugsent-notice"><p><?php esc_html_e('Disconnected from Plugsent.', 'plugsent-connector'); ?></p></div>
			<?php } elseif ($notice === 'revoked') { ?>
				<div class="notice notice-warning plugsent-notice"><p><?php esc_html_e('Plugsent revoked access for this site. Pair again to reconnect.', 'plugsent-connector'); ?></p></div>
			<?php } ?>

			<?php if ($connected) { ?>
				<div class="plugsent-card">
					<div class="plugsent-status">
						<span class="plugsent-dot"></span>
						<div>
							<strong><?php esc_html_e('Connected', 'plugsent-connector'); ?></strong>
							<?php $last = get_option(self::OPTION_LAST_SYNC); ?>
							<?php if ($last) { ?>
								<p class="plugsent-muted">
									<?php
                                    printf(
                                        /* translators: %s: date/time of last check-in */
                                        esc_html__('Last check-in: %s', 'plugsent-connector'),
                                        esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), $last))
                                    );
							    ?>
								</p>
							<?php } ?>
						</div>
					</div>
					<div class="plugsent-actions">
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<input type="hidden" name="action" value="plugsent_sync" />
							<?php wp_nonce_field('plugsent_sync'); ?>
							<?php submit_button(__('Sync now', 'plugsent-connector'), 'primary', 'submit', false); ?>
						</form>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
							onsubmit="return confirm('<?php esc_attr_e('Disconnect this site from Plugsent?', 'plugsent-connector'); ?>');">
							<input type="hidden" name="action" value="plugsent_disconnect" />
							<?php wp_nonce_field('plugsent_disconnect'); ?>
							<?php submit_button(__('Disconnect', 'plugsent-connector'), 'secondary', 'submit', false); ?>
						</form>
					</div>
				</div>
			<?php } else { ?>
				<div class="plugsent-card">
					<p class="plugsent-muted"><?php esc_html_e('Paste the credentials shown in your Plugsent dashboard under “Connect site”.', 'plugsent-connector'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="plugsent_pair" />
						<?php wp_nonce_field('plugsent_pair'); ?>
						<div class="plugsent-field">
							<label for="plugsent_connection"><?php esc_html_e('Connection string', 'plugsent-connector'); ?></label>
							<input type="text" id="plugsent_connection" name="plugsent_connection" class="regular-text code" required
								placeholder="https://plugsent.example.com::PLSG-XXXXXXXXXXXX" />
							<p class="plugsent-note"><?php esc_html_e('One single string — copy it from the Plugsent dashboard (Connect site → Copy connection string).', 'plugsent-connector'); ?></p>
						</div>
						<?php submit_button(__('Pair with Plugsent', 'plugsent-connector'), 'primary', 'submit', false); ?>
					</form>
				</div>
			<?php } ?>
		</div>
		<?php
    }

    public static function handle_pair()
    {
        if (! current_user_can('manage_options')) {
            wp_die();
        }
        check_admin_referer('plugsent_pair');

        // The connection string bundles both values: "server::credential".
        $raw = sanitize_text_field(wp_unslash($_POST['plugsent_connection'] ?? ''));
        $parsed = self::parse_connection_string($raw);

        if ($parsed === null) {
            self::redirect('bad_code');
        }

        [$server, $code] = $parsed;
        $server = untrailingslashit(esc_url_raw($server));
        $code = sanitize_text_field($code);

        if (empty($server) || empty($code)) {
            self::redirect('bad_code');
        }
        update_option(self::OPTION_SERVER, $server);

        $body = [
            'code' => $code,
            'site_url' => home_url(),
            'name' => get_bloginfo('name'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'capabilities' => [
                'inventory.get',
                'update.run',
                'plugin.activate',
                'plugin.deactivate',
                'plugin.delete',
                'theme.activate',
                'theme.delete',
            ],
        ];

        $response = self::http_post($server.'/connector/v1/pair', $body);

        if (is_wp_error($response)) {
            self::redirect('unreachable');
        }

        $code_status = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code_status !== 201 || empty($payload['site_key']) || empty($payload['site_secret'])) {
            self::redirect('bad_code');
        }

        update_option(self::OPTION_KEY, sanitize_text_field($payload['site_key']));
        $current_user = wp_get_current_user();
        if ($current_user->exists()) {
            update_option(self::OPTION_ADMIN_USER, $current_user->user_login);
        }
        update_option(self::OPTION_SECRET, sanitize_text_field($payload['site_secret']));
        update_option(self::OPTION_STATUS, 'connected');

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'plugsent_minute', self::CRON_HOOK);
        }
        wp_schedule_single_event(time(), self::CRON_HOOK);

        self::redirect('paired');
    }

    public static function handle_sync()
    {
        if (! current_user_can('manage_options')) {
            wp_die();
        }
        check_admin_referer('plugsent_sync');

        self::tick();
        self::redirect('synced');
    }

    public static function handle_disconnect()
    {
        if (! current_user_can('manage_options')) {
            wp_die();
        }
        check_admin_referer('plugsent_disconnect');

        self::forget_credentials();
        self::redirect('disconnected');
    }

    /**
     * One outbound cycle: poll for commands, execute them, report results.
     */
    public static function tick()
    {
        if (! self::is_connected()) {
            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        ignore_user_abort(true);

        $start = time();

        do {
            $activity = self::poll_once();

            if (! self::is_connected()) {
                return;
            }
        } while ($activity && (time() - $start) < 45);

        // Chain a follow-up run shortly after this request ends so the gap
        // between check-ins stays small (throttled to prevent pile-ups).
        if (! get_transient('plugsent_chain_lock')) {
            set_transient('plugsent_chain_lock', 1, 30);
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
            spawn_cron(time() + 5);
        }
    }

    /**
     * One outbound cycle: poll for commands, execute them, report results.
     * Returns true when any command was handled (i.e. there was activity).
     */
    private static function poll_once()
    {
        $response = self::signed_request(
            'poll',
            [
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'version' => PLUGSENT_CONNECTOR_VERSION,
                'wait' => 25,
                'capabilities' => [
                    'inventory.get',
                    'update.run',
                    'admin.login',
                    'plugin.activate',
                    'plugin.deactivate',
                    'plugin.delete',
                    'theme.activate',
                    'theme.delete',
                ],
                'health' => ['status' => 'ok'],
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status === 401) {
            // The platform revoked us; drop credentials and stop checking in.
            self::forget_credentials();
            update_option(self::OPTION_STATUS, 'revoked');

            return false;
        }

        if ($status !== 200) {
            return false;
        }

        update_option(self::OPTION_LAST_SYNC, time());

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        $results = [];

        $handled = false;

        foreach ((array) ($payload['commands'] ?? []) as $command) {
            $id = isset($command['id']) ? (int) $command['id'] : 0;
            // NOTE: do not sanitize_key() the type - it strips dots and
            // turns `inventory.get` into `inventoryget`. The type comes from
            // the signed platform response, so compare it exactly instead.
            $type = isset($command['type']) ? trim((string) $command['type']) : '';

            if ($id < 1) {
                continue;
            }

            // Guarantee an answer: if anything below fatals mid-command,
            // the shutdown handler reports it as failed so the platform
            // never shows a command as eternally running.
            self::guard_against_fatal($id);

            if ($type === 'inventory.get') {
                try {
                    self::post_result($id, 'ok', ['inventory' => self::command_inventory_get()]);
                } catch (Exception $e) {
                    self::post_result($id, 'failed', null, $e->getMessage());
                }
                $handled = true;

                continue;
            }

            if ($type === 'admin.login') {
                try {
                    self::post_result($id, 'ok', ['admin_login' => self::command_admin_login()]);
                } catch (Exception $e) {
                    self::post_result($id, 'failed', null, $e->getMessage());
                }
                $handled = true;

                continue;
            }

            if ($type === 'update.run') {
                $payload = isset($command['payload']) && is_array($command['payload']) ? $command['payload'] : [];
                try {
                    self::post_result($id, 'ok', ['update' => self::command_update_run($payload)]);
                } catch (Exception $e) {
                    self::post_result($id, 'failed', null, $e->getMessage());
                }
                $handled = true;

                continue;
            }

            if (
                $type === 'plugin.activate'
                || $type === 'plugin.deactivate'
                || $type === 'plugin.delete'
                || $type === 'theme.activate'
                || $type === 'theme.delete'
            ) {
                $payload = isset($command['payload']) && is_array($command['payload']) ? $command['payload'] : [];
                try {
                    self::post_result($id, 'ok', ['action' => self::command_manage_item($type, $payload)]);
                } catch (Exception $e) {
                    self::post_result($id, 'failed', null, $e->getMessage());
                }
                $handled = true;

                continue;
            }

            self::post_result($id, 'failed', null, 'unsupported_command');
            $handled = true;
        }

        return $handled;
    }

    /**
     * Update a single plugin, theme, or WordPress core.
     *
     * @param  array  $payload  {context: plugin|theme|core, slug: string}
     * @return array{context: string, slug: string, ok: bool, message: string, version: string|null}
     */
    private static function command_update_run($payload)
    {
        $context = isset($payload['context']) ? sanitize_key($payload['context']) : '';
        $slug = isset($payload['slug']) ? sanitize_text_field($payload['slug']) : '';

        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        if (! function_exists('get_plugin_updates')) {
            require_once ABSPATH.'wp-admin/includes/update.php';
        }
        if (! class_exists('WP_Upgrader')) {
            require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        }

        if ($context === 'plugin') {
            $target = self::resolve_plugin_file($slug);
            if ($target === null) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Plugin not found.', 'version' => null];
            }
            $plugins = (array) get_plugins();
            $before = isset($plugins[$target]['Version']) ? $plugins[$target]['Version'] : null;
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin);
            $result = $upgrader->upgrade($target);
            $plugins = (array) get_plugins();
            $after = isset($plugins[$target]['Version']) ? $plugins[$target]['Version'] : null;
            if (is_wp_error($result)) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => $result->get_error_message(), 'version' => $after];
            }
            $changed = ($after !== null && $after !== $before);

            return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => $changed ? 'Updated.' : 'No update was applied (possibly already up to date).', 'version' => $after];
        }

        if ($context === 'theme') {
            if (wp_get_theme($slug)->get('Version') === null && ! wp_get_theme($slug)->exists()) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Theme not found.', 'version' => null];
            }
            $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin);
            $result = $upgrader->upgrade($slug);
            $after = wp_get_theme($slug)->get('Version');
            if (is_wp_error($result)) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => $result->get_error_message(), 'version' => $after];
            }

            return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Updated.', 'version' => $after];
        }

        if ($context === 'core') {
            $before = get_bloginfo('version');
            $upgrader = new Core_Upgrader(new Automatic_Upgrader_Skin);
            $result = $upgrader->upgrade();
            if (is_wp_error($result)) {
                return ['context' => $context, 'slug' => 'wordpress', 'ok' => false, 'message' => $result->get_error_message(), 'version' => $before];
            }

            return ['context' => $context, 'slug' => 'wordpress', 'ok' => true, 'message' => 'Core update processed.', 'version' => get_bloginfo('version')];
        }

        return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Unknown update context.', 'version' => null];
    }

    /**
     * Resolve an inventory slug (e.g. `akismet`) to its plugin file path
     * (`akismet/akismet.php`) using the same rule as the platform inventory:
     * the directory name, or the sanitized title for single-file plugins.
     *
     * @return string|null
     */
    private static function resolve_plugin_file($slug)
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        foreach ((array) get_plugins() as $file => $info) {
            $candidate = dirname($file) !== '.' ? dirname($file) : sanitize_title($info['Name']);
            if ($candidate === $slug) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Activate, deactivate, delete, or switch a plugin/theme on this site.
     * The connector itself is off-limits: losing it means losing the site.
     *
     * @param  string  $type  plugin.activate|plugin.deactivate|plugin.delete|theme.activate|theme.delete
     * @param  array  $payload  {slug: string}
     * @return array{context: string, slug: string, ok: bool, message: string}
     */
    private static function command_manage_item($type, $payload)
    {
        [$context, $action] = explode('.', $type, 2);
        $slug = isset($payload['slug']) ? sanitize_text_field($payload['slug']) : '';

        if ($slug === '') {
            return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'No slug was provided.'];
        }

        if ($slug === 'plugsent-connector') {
            return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'The Plugsent connector cannot be managed remotely.'];
        }

        // Deletes go through WordPress's filesystem API, whose credential
        // helpers (request_filesystem_credentials, WP_Filesystem) live in
        // file.php - which is NOT loaded on WP-Cron requests.
        if (! function_exists('request_filesystem_credentials')) {
            require_once ABSPATH.'wp-admin/includes/file.php';
        }

        if ($context === 'plugin') {
            if (! function_exists('delete_plugins')) {
                require_once ABSPATH.'wp-admin/includes/plugin.php';
            }

            $target = self::resolve_plugin_file($slug);
            if ($target === null) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Plugin not found.'];
            }

            $active_plugins = (array) get_option('active_plugins', []);

            if ($action === 'activate') {
                if (in_array($target, $active_plugins, true)) {
                    return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Plugin is already active.'];
                }
                $result = activate_plugin($target);
                if (is_wp_error($result)) {
                    return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => $result->get_error_message()];
                }

                return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Activated.'];
            }

            if ($action === 'deactivate') {
                if (! in_array($target, $active_plugins, true)) {
                    return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Plugin is already inactive.'];
                }
                deactivate_plugins($target);
                $still_active = in_array($target, (array) get_option('active_plugins', []), true);
                if ($still_active) {
                    return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Plugin could not be deactivated.'];
                }

                return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Deactivated.'];
            }

            if (in_array($target, $active_plugins, true)) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Deactivate the plugin before deleting it.'];
            }
            $result = delete_plugins([$target]);
            if (is_wp_error($result) || $result === false) {
                $message = is_wp_error($result) ? $result->get_error_message() : 'WordPress refused to delete the plugin.';

                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => $message];
            }

            return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Deleted.'];
        }

        if ($context === 'theme') {
            $theme = wp_get_theme($slug);
            if (! $theme->exists()) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Theme not found.'];
            }

            if ($action === 'activate') {
                if (get_stylesheet() === $slug) {
                    return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Theme is already active.'];
                }
                switch_theme($slug);

                return ['context' => $context, 'slug' => $slug, 'ok' => get_stylesheet() === $slug, 'message' => 'Activated.'];
            }

            if (get_stylesheet() === $slug) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'The active theme cannot be deleted.'];
            }
            if (! function_exists('delete_theme')) {
                require_once ABSPATH.'wp-admin/includes/theme.php';
            }
            $result = delete_theme($slug);
            if (is_wp_error($result)) {
                return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => $result->get_error_message()];
            }

            return ['context' => $context, 'slug' => $slug, 'ok' => true, 'message' => 'Deleted.'];
        }

        return ['context' => $context, 'slug' => $slug, 'ok' => false, 'message' => 'Unknown management action.'];
    }

    /**
     * Create a single-use magic login URL for the paired admin user.
     *
     * @return array{url: string, user: string, expires_in: int}
     */
    private static function command_admin_login()
    {
        $login = get_option(self::OPTION_ADMIN_USER);

        if (empty($login)) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
            $login = $admins ? $admins[0]->user_login : '';
        }

        $user = $login ? get_user_by('login', $login) : false;

        if (! $user) {
            throw new Exception('No administrator user found on this site.');
        }

        $token = bin2hex(random_bytes(32));

        // DB-backed option, NOT a transient: on sites with Redis/Memcached
        // object caches, expiring transients are volatile and a login token
        // must never evaporate before use.
        update_option('plugsent_login_token', [
            'hash' => hash('sha256', $token),
            'user' => $user->user_login,
            'expires' => time() + 300,
        ], false);

        return [
            'url' => add_query_arg('plugsent_login', $token, wp_login_url()),
            'user' => $user->user_login,
            'expires_in' => 300,
        ];
    }

    /**
     * Single-use magic login: consumes the token from an admin.login command
     * and signs the paired administrator into wp-admin.
     */
    public static function maybe_magic_login()
    {
        $token = isset($_GET['plugsent_login']) ? sanitize_text_field(wp_unslash($_GET['plugsent_login'])) : '';

        if ($token === '') {
            return;
        }
        // DB-backed lookup: options survive object-cache eviction and flushes,
        // unlike transients on sites with Redis/Memcached.
        $stored = get_option('plugsent_login_token');

        if (! is_array($stored) || empty($stored['hash'])) {
            wp_die(esc_html__('No pending Plugsent login link was found. Generate a new one from the dashboard.', 'plugsent-connector'), '', ['response' => 403]);
        }

        if (! hash_equals($stored['hash'], hash('sha256', $token))) {
            wp_die(esc_html__('This Plugsent login link was already used or is invalid. Generate a new one from the dashboard.', 'plugsent-connector'), '', ['response' => 403]);
        }

        if (time() > (int) $stored['expires']) {
            delete_option('plugsent_login_token');
            wp_die(esc_html__('This Plugsent login link has expired. Generate a new one from the dashboard.', 'plugsent-connector'), '', ['response' => 403]);
        }

        delete_option('plugsent_login_token');

        $user = get_user_by('login', $stored['user']);

        if (! $user) {
            wp_die(esc_html__('The paired admin user no longer exists on this site.', 'plugsent-connector'), '', ['response' => 403]);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        do_action('wp_login', $user->user_login, $user);

        wp_safe_redirect(admin_url());
        exit;
    }

    /**
     * @return array{core: array, plugins: array, themes: array}
     */
    private static function command_inventory_get()
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        if (! function_exists('get_plugin_updates')) {
            require_once ABSPATH.'wp-admin/includes/update.php';
        }

        $active_plugins = (array) get_option('active_plugins', []);
        $plugin_updates = (array) get_plugin_updates();

        $plugins = [];
        foreach ((array) get_plugins() as $file => $plugin) {
            $update_version = null;
            if (isset($plugin_updates[$file]->update->new_version)) {
                $update_version = $plugin_updates[$file]->update->new_version;
            }
            $plugins[] = [
                'slug' => dirname($file) !== '.' ? dirname($file) : sanitize_title($plugin['Name']),
                'name' => $plugin['Name'],
                'version' => $plugin['Version'],
                'update_available' => $update_version !== null,
                'update_version' => $update_version,
                'active' => in_array($file, $active_plugins, true),
            ];
        }

        $themes = [];
        $theme_updates = (array) get_theme_updates();
        $active_theme = get_stylesheet();
        foreach (wp_get_themes() as $slug => $theme) {
            $update_version = null;
            $update = $theme_updates[$slug]->update ?? null;
            if (is_array($update) && isset($update['new_version'])) {
                $update_version = $update['new_version'];
            } elseif (is_object($update) && isset($update->new_version)) {
                $update_version = $update->new_version;
            }
            $themes[] = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'update_available' => $update_version !== null,
                'update_version' => $update_version,
                'active' => ($slug === $active_theme),
            ];
        }

        $core = [
            'slug' => 'wordpress',
            'name' => 'WordPress',
            'version' => get_bloginfo('version'),
            'update_available' => false,
            'update_version' => null,
            'active' => true,
        ];
        $core_updates = get_site_transient('update_core');
        if (! empty($core_updates->updates) && is_array($core_updates->updates)) {
            foreach ($core_updates->updates as $update) {
                if (isset($update->response) && $update->response === 'upgrade' && ! empty($update->version)) {
                    $core['update_available'] = true;
                    $core['update_version'] = $update->version;
                    break;
                }
            }
        }

        return [
            'core' => [$core],
            'plugins' => $plugins,
            'themes' => $themes,
        ];
    }

    private static function signed_request($path, $body_array)
    {
        return self::http_post(
            get_option(self::OPTION_SERVER).'/connector/v1/'.$path,
            $body_array,
            true
        );
    }

    private static function http_post($url, $body_array, $signed = false)
    {
        $body = wp_json_encode($body_array);
        $headers = ['Content-Type' => 'application/json'];

        if ($signed) {
            $timestamp = time();
            $headers['X-Plugsent-Key'] = (string) get_option(self::OPTION_KEY);
            $headers['X-Plugsent-Timestamp'] = (string) $timestamp;
            $headers['X-Plugsent-Nonce'] = wp_generate_uuid4();
            $headers['X-Plugsent-Signature'] = Plugsent_Connector_Signer::sign(
                (string) get_option(self::OPTION_SECRET),
                $timestamp,
                (string) $body
            );
        }

        return wp_remote_post(
            $url,
            [
                'timeout' => 45,
                'headers' => $headers,
                'body' => $body,
            ]
        );
    }

    private static function forget_credentials()
    {
        delete_option(self::OPTION_KEY);
        delete_option(self::OPTION_SECRET);
        delete_option(self::OPTION_LAST_SYNC);
        update_option(self::OPTION_STATUS, 'disconnected');
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    private static function redirect($message)
    {
        wp_safe_redirect(add_query_arg('plugsent_msg', $message, admin_url('options-general.php?page='.self::PAGE_SLUG)));
        exit;
    }
}
