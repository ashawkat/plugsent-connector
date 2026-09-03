<?php
/**
 * Vendored copy of plugsent/connector-signing (packages/connector-signing/src/Signer.php).
 *
 * Keep this file byte-compatible with the package: both implementations are
 * exercised against the same test vectors in the Plugsent repository so a
 * divergence fails CI. Protocol v1.
 *
 * @package Plugsent_Connector
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * HMAC request signing for the Plugsent connector protocol (v1).
 */
final class Plugsent_Connector_Signer {

	const DEFAULT_TOLERANCE = 300;

	/**
	 * Compute the request signature.
	 *
	 * @param string $secret    Site secret.
	 * @param int    $timestamp Unix seconds.
	 * @param string $body      Raw request body.
	 * @return string Hex signature.
	 */
	public static function sign( $secret, $timestamp, $body ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Verify a signature, including timestamp tolerance.
	 *
	 * @param string $secret    Site secret.
	 * @param int    $timestamp Unix seconds.
	 * @param string $body      Raw request body.
	 * @param string $signature Hex signature.
	 * @param int    $tolerance Allowed clock drift in seconds.
	 * @return bool
	 */
	public static function verify( $secret, $timestamp, $body, $signature, $tolerance = self::DEFAULT_TOLERANCE ) {
		if ( abs( time() - (int) $timestamp ) > $tolerance ) {
			return false;
		}

		if ( ! is_string( $signature ) || ! ctype_xdigit( $signature ) || 64 !== strlen( $signature ) ) {
			return false;
		}

		return hash_equals( self::sign( $secret, (int) $timestamp, $body ), strtolower( $signature ) );
	}

	/**
	 * Generate a site key + secret pair.
	 *
	 * @return array{site_key: string, site_secret: string}
	 */
	public static function generate_key_pair() {
		return array(
			'site_key'    => 'pk_' . bin2hex( random_bytes( 12 ) ),
			'site_secret' => bin2hex( random_bytes( 32 ) ),
		);
	}

	/**
	 * Generate a one-time pairing code.
	 *
	 * @return string
	 */
	public static function pairing_code() {
		return 'PLSG-' . strtoupper( bin2hex( random_bytes( 6 ) ) );
	}

	/**
	 * Normalize + hash a pairing code for transport-safe storage.
	 *
	 * @param string $code Pairing code.
	 * @return string
	 */
	public static function code_hash( $code ) {
		return hash( 'sha256', trim( strtoupper( $code ) ) );
	}
}
