<?php

defined( 'ABSPATH' ) || exit;

class WPVibe_Connection_Status {
	const OPTION = 'wpvibe_connection_status';
	const ROUTE = '/wpvibe/v1/connection-status';
	const MAX_AGE = 300;
	const STATES = array( 'verified', 'limited', 'failing:auth_blocked', 'failing:credential_rejected', 'failing:app_passwords_unavailable', 'stored_unverified' );

	protected static function public_keys() { return WPVibe_Op_Proof_V2::PUBLIC_KEYS; }
	protected static function verify_proof( $request ) { return WPVibe_Op_Proof::verify( $request, self::ROUTE, self::subject( $request ) ); }
	public static function awaiting_confirmation() {
		$at = (int) get_option( 'wpvibe_authorization_pending_at', 0 );
		$record = static::current_observation();
		return $at > time() - DAY_IN_SECONDS && ( ! $record || $record['observed_at'] < $at * 1000 );
	}

	public static function subject( $request ) {
		$source = $request->get_param( 'source' );
		$parts = array( null === $source ? 'connection-status-v1' : 'connection-status-v2', $request->get_param( 'connection_id' ), $request->get_param( 'generation' ), $request->get_param( 'observed_at' ), $request->get_param( 'state' ), $request->get_param( 'via' ) );
		if ( null !== $source ) { $parts[] = $source; $parts[] = $request->get_param( 'client' ); }
		return implode( "\n", $parts );
	}

	private static function error( $code, $message, $status = 403 ) {
		return new WP_Error( 'wpvibe_connection_' . $code, $message, array( 'status' => $status ) );
	}

	public static function authorize( $request ) {
		foreach ( array( 'connection_id', 'generation' ) as $field ) {
			$value = $request->get_param( $field );
			if ( ! is_string( $value ) || ! preg_match( '/^[a-zA-Z0-9_-]{8,80}$/', $value ) ) {
				return self::error( 'invalid', 'Invalid connection identifier.', 400 );
			}
		}
		$observed = $request->get_param( 'observed_at' );
		if ( ! is_int( $observed ) || abs( (int) floor( $observed / 1000 ) - time() ) > self::MAX_AGE || ! in_array( $request->get_param( 'state' ), self::STATES, true ) || ! in_array( $request->get_param( 'via' ), array( 'direct', 'relay', 'unknown' ), true ) ) {
			return self::error( 'invalid', 'Invalid or expired connection observation.', 400 );
		}
		$source = $request->get_param( 'source' );
		$client = $request->get_param( 'client' );
		if ( null !== $source && ( 'mcp_site_info' !== $source || ! in_array( $request->get_param( 'state' ), array( 'verified', 'limited' ), true ) || ! is_string( $client ) || ! preg_match( '~^[a-zA-Z0-9 ._()/+\-]{0,80}$~D', $client ) ) ) {
			return self::error( 'invalid', 'Invalid MCP read observation.', 400 );
		}
		$proof = (string) $request->get_header( WPVibe_Op_Proof_V2::HEADER );
		if ( ! preg_match( '/^v2\.([a-zA-Z0-9_-]{1,64})\.([0-9]{1,12})\.[a-zA-Z0-9_-]{86}$/D', $proof, $parts ) || (int) $parts[2] > time() + self::MAX_AGE ) {
			return self::error( 'unsigned', 'A recent WPVibe-signed observation is required.' );
		}
		return static::verify_proof( $request );
	}

	public static function record( $request ) {
		$allowed = static::authorize( $request );
		if ( true !== $allowed ) {
			return $allowed;
		}
		$previous = get_option( self::OPTION, false );
		$records = is_array( $previous ) ? $previous : array();
		$id = $request->get_param( 'connection_id' );
		$fingerprint = 'v2:' . explode( '.', (string) $request->get_header( WPVibe_Op_Proof_V2::HEADER ) )[1];
		$old = isset( $records[ $id ] ) ? $records[ $id ] : null;
		if ( is_array( $old ) && ( $old['key'] === $fingerprint || static::trusted_record( $old ) ) && $old['observed_at'] >= $request->get_param( 'observed_at' ) ) {
			return self::error( 'stale', 'A newer connection observation is already stored.', 409 );
		}
		$records[ $id ] = array( 'key' => $fingerprint, 'generation' => $request->get_param( 'generation' ), 'observed_at' => $request->get_param( 'observed_at' ), 'state' => $request->get_param( 'state' ), 'via' => $request->get_param( 'via' ) );
		if ( 'mcp_site_info' === $request->get_param( 'source' ) ) {
			$records[ $id ]['ai_read'] = array( 'observed_at' => $request->get_param( 'observed_at' ), 'client' => $request->get_param( 'client' ) );
		} elseif ( is_array( $old ) && $old['key'] === $fingerprint && $old['generation'] === $request->get_param( 'generation' ) && in_array( $request->get_param( 'state' ), array( 'verified', 'limited' ), true ) && isset( $old['ai_read'] ) ) {
			$records[ $id ]['ai_read'] = $old['ai_read'];
		}
		uasort( $records, function ( $a, $b ) { return $b['observed_at'] <=> $a['observed_at']; } );
		$records = array_slice( $records, 0, 16, true );
		if ( false === $previous ) {
			$saved = add_option( self::OPTION, $records, '', false );
		} else {
			// Conditional SQL prevents a slower request replacing a newer cached observation.
			global $wpdb;
			$saved = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize( $records ), self::OPTION, maybe_serialize( $previous ) ) );
			wp_cache_delete( self::OPTION, 'options' );
		}
		if ( ! $saved ) {
			return self::error( 'conflict', 'The connection observation changed while this request was running. Check again.', 409 );
		}
		return rest_ensure_response( array( 'recorded' => true ) );
	}

	protected static function trusted_record( $record ) {
		$key = $record['key'] ?? '';
		return 0 === strpos( $key, 'v2:' ) && isset( static::public_keys()[ substr( $key, 3 ) ] );
	}

	public static function current_observation() {
		$records = get_option( self::OPTION, array() );
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( is_array( $record ) && static::trusted_record( $record ) && in_array( $record['state'] ?? '', self::STATES, true ) ) { return $record; }
		}
		return null;
	}

	public static function current_ai_read() {
		$record = static::current_observation();
		return $record && in_array( $record['state'], array( 'verified', 'limited' ), true ) && isset( $record['ai_read'] ) && is_array( $record['ai_read'] ) ? $record['ai_read'] : null;
	}

	public static function current_state() {
		$record = static::current_observation();
		return $record ? $record['state'] : 'not_checked';
	}

	public static function badge() {
		$records = get_option( self::OPTION, array() );
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! is_array( $record ) || ! static::trusted_record( $record ) ) {
					continue;
				}
				$stamp = gmdate( 'Y-m-d H:i', (int) floor( $record['observed_at'] / 1000 ) ) . ' UTC';
				if ( 'verified' === $record['state'] ) {
					return sprintf( __( 'Connected. Verified %s.', 'vibe-ai' ), $stamp );
				}
				if ( 'limited' === $record['state'] ) {
					return sprintf( __( 'Connected with limited access. Verified %s.', 'vibe-ai' ), $stamp );
				}
				return sprintf( __( 'Connection needs attention (checked %s).', 'vibe-ai' ), $stamp );
			}
		}
		if ( self::awaiting_confirmation() ) {
			return __( 'Approved, waiting for confirmation', 'vibe-ai' );
		}
		$last_active = (int) get_option( 'wpvibe_last_active', 0 );
		if ( $last_active > 0 && WPVibe_White_Label::site_is_connected() ) {
			return sprintf( __( 'Connected. Your AI used this site %s ago.', 'vibe-ai' ), human_time_diff( $last_active ) );
		}
		return __( 'Not connected yet', 'vibe-ai' );
	}

	public static function check_url() {
		return 'https://mcp.wpvibe.ai/account/sites?site_url=' . rawurlencode( site_url() );
	}

	public static function has_connection_history() {
		return 'not_checked' !== self::current_state() || (int) get_option( 'wpvibe_last_active', 0 ) > 0;
	}
}
