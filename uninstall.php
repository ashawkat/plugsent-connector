<?php

/**
 * Remove all plugin data on uninstall.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('plugsent_server_url');
delete_option('plugsent_site_key');
delete_option('plugsent_site_secret');
delete_option('plugsent_status');
delete_option('plugsent_last_sync');
wp_clear_scheduled_hook('plugsent_connector_tick');
