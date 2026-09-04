<?php

/**
 * Plugin Name:       Plugsent Connector
 * Plugin URI:        https://github.com/plugsent/plugsent
 * Description:       Connects this WordPress site to your self-hosted Plugsent control plane: plugin/theme/core inventory, safe updates, and uptime reporting. Outbound-only — works behind firewalls.
 * Version:           0.9.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            BetaTech
 * Author URI:        https://github.com/plugsent
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plugsent-connector
 */
if (! defined('ABSPATH')) {
    exit;
}

define('PLUGSENT_CONNECTOR_VERSION', '0.9.1');
define('PLUGSENT_CONNECTOR_FILE', __FILE__);

require_once __DIR__.'/includes/class-plugsent-signer.php';
require_once __DIR__.'/includes/class-plugsent-connector.php';

Plugsent_Connector::init();

register_activation_hook(__FILE__, ['Plugsent_Connector', 'activate']);
register_deactivation_hook(__FILE__, ['Plugsent_Connector', 'deactivate']);
