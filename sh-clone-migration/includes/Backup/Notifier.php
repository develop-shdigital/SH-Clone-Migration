<?php
/**
 * Backup notification e-mails.
 *
 * @package SHCM
 */

namespace SHCM\Backup;

use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Builds the plain-text e-mail sent after a backup run.
 *
 * The message says plainly what happened and what exists where; it never
 * contains a download link that would work without logging in.
 */
final class Notifier {

	/**
	 * Compose a message.
	 *
	 * @param array  $entry History entry.
	 * @param string $site  Site label.
	 * @param string $url   Scheduled Backups screen.
	 * @return array array( subject, body )
	 */
	public static function compose( array $entry, $site, $url ) {
		$status = isset( $entry['status'] ) ? (string) $entry['status'] : 'failed';
		$error  = isset( $entry['error'] ) ? trim( (string) $entry['error'] ) : '';

		switch ( $status ) {
			case 'success':
				$subject = sprintf( self::t( '[%s] Backup completed' ), $site );
				$lead    = self::t( 'A backup of your site was completed.' );
				break;
			case 'partial':
				$subject = sprintf( self::t( '[%s] Backup completed, but it was not uploaded to Google Drive' ), $site );
				$lead    = self::t( 'A backup of your site was made and kept on the server, but the copy on Google Drive could not be made.' );
				break;
			case 'skipped':
				$subject = sprintf( self::t( '[%s] Scheduled backup skipped' ), $site );
				$lead    = self::t( 'The scheduled backup did not run.' );
				break;
			default:
				$subject = sprintf( self::t( '[%s] Backup failed' ), $site );
				$lead    = self::t( 'The backup of your site failed. No new backup was made.' );
		}

		$lines   = array( $lead, '' );
		$lines[] = sprintf( self::t( 'Site: %s' ), $site );
		if ( ! empty( $entry['started'] ) ) {
			$lines[] = sprintf( self::t( 'Started: %s' ), self::date( (int) $entry['started'] ) );
		}
		if ( ! empty( $entry['trigger'] ) ) {
			$lines[] = sprintf( self::t( 'Started by: %s' ), self::trigger( (string) $entry['trigger'] ) );
		}
		if ( ! empty( $entry['archive'] ) && in_array( $status, array( 'success', 'partial' ), true ) ) {
			$lines[] = sprintf( self::t( 'Archive: %s' ), $entry['archive'] );
			if ( ! empty( $entry['size'] ) ) {
				$lines[] = sprintf( self::t( 'Size: %1$s (%2$s bytes)' ), Bytes::format( (int) $entry['size'] ), number_format( (int) $entry['size'] ) );
			}
			if ( ! empty( $entry['sha256'] ) ) {
				$lines[] = sprintf( self::t( 'SHA-256: %s' ), $entry['sha256'] );
			}
			if ( isset( $entry['database']['included'] ) ) {
				$lines[] = ! empty( $entry['database']['included'] )
					? sprintf( self::t( 'Database: %1$s tables, %2$s rows' ), number_format( (int) $entry['database']['tables'] ), number_format( (int) $entry['database']['rows'] ) )
					: self::t( 'Database: not included' );
			}
			$remote = isset( $entry['remote'] ) && is_array( $entry['remote'] ) ? $entry['remote'] : array();
			if ( isset( $remote['status'] ) && 'uploaded' === $remote['status'] ) {
				$lines[] = self::t( 'Google Drive: uploaded and verified' ) . ( ! empty( $remote['link'] ) ? ' - ' . $remote['link'] : '' );
			} elseif ( isset( $remote['status'] ) && 'off' !== $remote['status'] ) {
				$lines[] = self::t( 'Google Drive: not uploaded' );
			}
			if ( ! empty( $entry['warnings'] ) ) {
				$lines[] = sprintf( self::t( 'Warnings: %d (see the log)' ), (int) $entry['warnings'] );
			}
		}
		if ( '' !== $error ) {
			$lines[] = '';
			$lines[] = self::t( 'Reason:' );
			$lines[] = $error;
		}
		$lines[] = '';
		$lines[] = self::t( 'Details and the log:' );
		$lines[] = $url;
		$lines[] = '';
		$lines[] = '-- ';
		$lines[] = self::t( 'SH Clone Migration' );

		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * Translate.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function t( $text ) {
		return function_exists( '__' ) ? __( $text, 'sh-clone-migration' ) : $text; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	/**
	 * Localised date.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	private static function date( $timestamp ) {
		if ( function_exists( 'wp_date' ) && function_exists( 'get_option' ) ) {
			return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		}
		return gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
	}

	/**
	 * Describe what started a run.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	private static function trigger( $trigger ) {
		switch ( $trigger ) {
			case 'schedule':
				return self::t( 'the schedule' );
			case 'cli':
				return self::t( 'WP-CLI' );
			default:
				return self::t( 'an administrator (Back up now)' );
		}
	}
}
