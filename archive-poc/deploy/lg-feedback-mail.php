<?php
/**
 * lg-feedback-mail — loopback bridge for the archive-poc front-page
 * "Report a bug or suggestion" modal.
 *
 * archive-poc runs in its own FPM pool with no in-process WP/mailer, so it
 * POSTs here over loopback; this endpoint calls wp_mail() → FluentSMTP → SES,
 * the SAME path every other site email uses. No system MTA / SMTP creds needed.
 *
 * Surface: POST /wp-json/looth/v1/feedback-send
 *   header  X-LG-Feedback-Secret: <shared secret>
 *   body    {"subject": "...", "body": "..."}   (plain text)
 *
 * Auth: shared secret at /etc/lg-archive-poc-secret (the same file the
 *   archive-poc /_config webhook uses; ACL must grant the WP pool user read).
 *   Empty/missing secret → endpoint refuses everything.
 *
 * The destination address is SERVER-SIDE ONLY here — it is never accepted from
 * the client and never appears in HTML/JS.
 */

defined( 'ABSPATH' ) || exit;

const LG_FEEDBACK_DEST = 'ian.davlin@gmail.com';

add_action( 'rest_api_init', function () {
	register_rest_route( 'looth/v1', '/feedback-send', [
		'methods'             => 'POST',
		'permission_callback' => 'lg_feedback_mail_authorized',
		'callback'            => 'lg_feedback_mail_send',
	] );
} );

function lg_feedback_mail_secret(): string {
	$s = @file_get_contents( '/etc/lg-archive-poc-secret' );
	return $s === false ? '' : trim( $s );
}

function lg_feedback_mail_authorized( WP_REST_Request $req ): bool {
	$want = lg_feedback_mail_secret();
	if ( $want === '' ) {
		return false; // no secret on disk → refuse all
	}
	$got = (string) $req->get_header( 'x-lg-feedback-secret' );
	return $got !== '' && hash_equals( $want, $got );
}

function lg_feedback_mail_send( WP_REST_Request $req ) {
	$p       = (array) $req->get_json_params();
	$subject = trim( (string) ( $p['subject'] ?? '' ) );
	$body    = trim( (string) ( $p['body'] ?? '' ) );
	if ( $subject === '' || $body === '' ) {
		return new WP_Error( 'lg_fb_empty', 'subject and body required', [ 'status' => 400 ] );
	}
	if ( strlen( $subject ) > 200 ) {
		$subject = substr( $subject, 0, 200 );
	}
	if ( strlen( $body ) > 20000 ) {
		$body = substr( $body, 0, 20000 );
	}
	$ok = wp_mail( LG_FEEDBACK_DEST, $subject, $body );
	if ( ! $ok ) {
		return new WP_Error( 'lg_fb_send_failed', 'wp_mail returned false', [ 'status' => 502 ] );
	}
	return rest_ensure_response( [ 'ok' => true ] );
}
