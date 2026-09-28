<?php
/**
 * Backup schedule model.
 *
 * @package SHCM
 */

namespace SHCM\Backup;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * The backup schedule as plain data: validation, the time of the next run
 * and a human readable summary.
 *
 * Nothing here reads WordPress state, the clock or the filesystem, so the
 * rules can be unit tested, in particular around daylight saving time, where
 * "same time tomorrow" computed as "+86400 seconds" skips or doubles runs.
 *
 * The backup password is not part of this configuration: BackupManager keeps
 * it sealed in the runtime state.
 */
final class Schedule {

	const FREQUENCIES = array( 'manual', 'daily', 'weekly', 'monthly' );
	const CONTENTS    = array( 'full', 'database', 'files' );
	const NOTIFY_ON   = array( 'failure', 'always', 'never' );

	/**
	 * Monthday value meaning "the last day of the month".
	 */
	const LAST_DAY = -1;

	/**
	 * Highest selectable day of the month: every month has it.
	 */
	const MAX_MONTHDAY = 28;

	const MAX_EXCLUSIONS     = 100;
	const MAX_PATTERN_LENGTH = 255;
	const MAX_KEEP_LOCAL     = 100;
	const MIN_KEEP_REMOTE    = 1;
	const MAX_KEEP_REMOTE    = 1000;

	/**
	 * Fields where an empty string is a real value rather than "not sent".
	 */
	const EMPTY_ALLOWED = array( 'include_core', 'gdrive', 'encrypt', 'exclusions', 'notify_email' );

	/**
	 * Default configuration.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'frequency'    => 'manual',
			'time'         => '03:00',
			'weekday'      => 0,
			'monthday'     => 1,
			'contents'     => 'full',
			'include_core' => false,
			'exclusions'   => array(),
			'keep_local'   => 3,
			'gdrive'       => false,
			'keep_remote'  => 10,
			'encrypt'      => false,
			'notify_on'    => 'failure',
			'notify_email' => '',
		);
	}

	/**
	 * Validate and normalise a configuration.
	 *
	 * $input is merged over $current, which is merged over the defaults.
	 * Unknown keys are dropped. A key that is absent from $input, null, or
	 * (for settings that cannot be empty) an empty string keeps its current
	 * value, so partial updates work; a form must therefore send unchecked
	 * checkboxes as false. Invalid values in $input throw. Invalid values in
	 * $current (a damaged file) fall back to the default of that field
	 * instead, so one bad field cannot reset, and thereby switch off, the
	 * whole schedule.
	 *
	 * @param array $input   Submitted values.
	 * @param array $current Current (stored) configuration.
	 * @return array Complete configuration, keys in the order of defaults().
	 * @throws \InvalidArgumentException When a submitted value is invalid (translated message).
	 */
	public static function sanitize( array $input, array $current = array() ) {
		$config = self::defaults();

		foreach ( array_keys( $config ) as $key ) {
			if ( ! array_key_exists( $key, $current ) || self::isAbsent( $key, $current[ $key ] ) ) {
				continue;
			}
			try {
				$config[ $key ] = self::normalizeField( $key, $current[ $key ] );
			} catch ( \InvalidArgumentException $e ) {
				// Keep the default for this field (see above).
				continue;
			}
		}

		foreach ( array_keys( $config ) as $key ) {
			if ( ! array_key_exists( $key, $input ) || self::isAbsent( $key, $input[ $key ] ) ) {
				continue;
			}
			$config[ $key ] = self::normalizeField( $key, $input[ $key ] );
		}

		// Without local copies and without Drive a backup would be deleted
		// as soon as it is made.
		if ( 0 === $config['keep_local'] && ! $config['gdrive'] ) {
			throw self::error( 'keep_none' );
		}

		return $config;
	}

	/**
	 * Time of the next scheduled run.
	 *
	 * Candidates are wall-clock times in $tz: today or a later day (daily),
	 * the next matching weekday (weekly), day N or the last day of this or a
	 * later month (monthly). A wall time skipped when the clocks go forward
	 * moves forward by the length of the gap (02:30 becomes 03:30, as PHP
	 * does), past midnight if the gap crosses it; a wall time that occurs
	 * twice when the clocks go back takes its first occurrence, so a run is
	 * never repeated an hour later.
	 *
	 * @param array         $config Configuration (see sanitize()).
	 * @param \DateTimeZone $tz     Site time zone.
	 * @param int           $now    Current Unix time.
	 * @return int|null Unix time strictly after $now, or null when backups only run on demand.
	 */
	public static function nextRun( array $config, \DateTimeZone $tz, $now ) {
		$timing = self::timing( $config );
		if ( 'manual' === $timing['frequency'] ) {
			return null;
		}

		$now    = (int) $now;
		$hour   = (int) substr( $timing['time'], 0, 2 );
		$minute = (int) substr( $timing['time'], 3, 2 );
		$local  = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$year   = (int) $local->format( 'Y' );
		$month  = (int) $local->format( 'n' );
		$day    = (int) $local->format( 'j' );

		// Calendar arithmetic is done on UTC dates, where every day has 24
		// hours; only the final wall time is resolved in the site time zone.
		// The search starts one period back: when a gap crosses midnight
		// (America/Nuuk jumps from Saturday 23:00 to Sunday 00:00), the run
		// of the previous day or month moves forward into today and can
		// still lie ahead. Candidates never decrease, so the first one after
		// $now is the earliest.
		if ( 'monthly' === $timing['frequency'] ) {
			for ( $offset = -1; $offset <= 24; $offset++ ) {
				$first = gmmktime( 0, 0, 0, $month + $offset, 1, $year );
				$date  = self::LAST_DAY === $timing['monthday'] ? (int) gmdate( 't', $first ) : $timing['monthday'];
				$when  = self::wallTime( $tz, (int) gmdate( 'Y', $first ), (int) gmdate( 'n', $first ), $date, $hour, $minute );
				if ( $when > $now ) {
					return $when;
				}
			}
		} else {
			for ( $offset = -1; $offset <= 14; $offset++ ) {
				$date = gmmktime( 0, 0, 0, $month, $day + $offset, $year );
				if ( 'weekly' === $timing['frequency'] && (int) gmdate( 'w', $date ) !== $timing['weekday'] ) {
					continue;
				}
				$when = self::wallTime( $tz, (int) gmdate( 'Y', $date ), (int) gmdate( 'n', $date ), (int) gmdate( 'j', $date ), $hour, $minute );
				if ( $when > $now ) {
					return $when;
				}
			}
		}

		// Not reachable with a real time zone; never hand out a past time.
		return $now + 86400;
	}

	/**
	 * Human readable summary, e.g. "Weekly on Sunday at 03:00".
	 *
	 * @param array $config Configuration (see sanitize()).
	 * @return string
	 */
	public static function describe( array $config ) {
		$timing = self::timing( $config );
		$wp     = self::canTranslate();

		switch ( $timing['frequency'] ) {
			case 'daily':
				/* translators: %s: time of day on a 24-hour clock, e.g. 03:00 */
				return sprintf( $wp ? __( 'Daily at %s', 'sh-clone-migration' ) : 'Daily at %s', $timing['time'] );

			case 'weekly':
				$names = self::weekdayNames();
				/* translators: 1: weekday name, 2: time of day on a 24-hour clock, e.g. 03:00 */
				return sprintf( $wp ? __( 'Weekly on %1$s at %2$s', 'sh-clone-migration' ) : 'Weekly on %1$s at %2$s', $names[ $timing['weekday'] ], $timing['time'] );

			case 'monthly':
				if ( self::LAST_DAY === $timing['monthday'] ) {
					/* translators: %s: time of day on a 24-hour clock, e.g. 03:00 */
					return sprintf( $wp ? __( 'Monthly on the last day at %s', 'sh-clone-migration' ) : 'Monthly on the last day at %s', $timing['time'] );
				}
				/* translators: 1: day of the month (1 to 28), 2: time of day on a 24-hour clock, e.g. 03:00 */
				return sprintf( $wp ? __( 'Monthly on day %1$d at %2$s', 'sh-clone-migration' ) : 'Monthly on day %1$d at %2$s', $timing['monthday'], $timing['time'] );

			default:
				return $wp ? __( 'Only on demand', 'sh-clone-migration' ) : 'Only on demand';
		}
	}

	/**
	 * Normalise a time of day.
	 *
	 * Accepts "H:MM" and "HH:MM" (and ignores seconds, "HH:MM:SS", which a
	 * browser time field can send).
	 *
	 * @param mixed $value Time.
	 * @return string|null "HH:MM" (00:00 to 23:59), or null when invalid.
	 */
	public static function normalizeTime( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])(?::[0-5][0-9])?\z/', trim( $value ), $match ) ) {
			return null;
		}
		return sprintf( '%02d:%s', (int) $match[1], $match[2] );
	}

	/**
	 * Weekday names indexed like PHP's date( 'w' ): 0 = Sunday.
	 *
	 * @return string[]
	 */
	public static function weekdayNames() {
		if ( ! self::canTranslate() ) {
			return array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		}
		return array(
			__( 'Sunday', 'sh-clone-migration' ),
			__( 'Monday', 'sh-clone-migration' ),
			__( 'Tuesday', 'sh-clone-migration' ),
			__( 'Wednesday', 'sh-clone-migration' ),
			__( 'Thursday', 'sh-clone-migration' ),
			__( 'Friday', 'sh-clone-migration' ),
			__( 'Saturday', 'sh-clone-migration' ),
		);
	}

	/**
	 * Whether a submitted value means "not sent, keep the current one".
	 *
	 * @param string $key   Field.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	private static function isAbsent( $key, $value ) {
		if ( null === $value ) {
			return true;
		}
		return '' === $value && ! in_array( $key, self::EMPTY_ALLOWED, true );
	}

	/**
	 * Validate one field.
	 *
	 * @param string $key   Field.
	 * @param mixed  $value Value.
	 * @return mixed Normalised value.
	 * @throws \InvalidArgumentException When the value is invalid.
	 */
	private static function normalizeField( $key, $value ) {
		switch ( $key ) {
			case 'frequency':
			case 'contents':
			case 'notify_on':
				$allowed = array(
					'frequency' => self::FREQUENCIES,
					'contents'  => self::CONTENTS,
					'notify_on' => self::NOTIFY_ON,
				);
				$value   = is_string( $value ) ? strtolower( trim( $value ) ) : null;
				if ( null === $value || ! in_array( $value, $allowed[ $key ], true ) ) {
					throw self::error( $key );
				}
				return $value;

			case 'time':
				$time = self::normalizeTime( $value );
				if ( null === $time ) {
					throw self::error( 'time' );
				}
				return $time;

			case 'weekday':
				$weekday = self::toInt( $value );
				if ( null === $weekday || $weekday < 0 || $weekday > 6 ) {
					throw self::error( 'weekday' );
				}
				return $weekday;

			case 'monthday':
				if ( is_string( $value ) && 'last' === strtolower( trim( $value ) ) ) {
					return self::LAST_DAY;
				}
				$monthday = self::toInt( $value );
				if ( null === $monthday || ( self::LAST_DAY !== $monthday && ( $monthday < 1 || $monthday > self::MAX_MONTHDAY ) ) ) {
					throw self::error( 'monthday' );
				}
				return $monthday;

			case 'include_core':
			case 'gdrive':
			case 'encrypt':
				$flag = self::toBool( $value );
				if ( null === $flag ) {
					throw self::error( $key );
				}
				return $flag;

			case 'exclusions':
				return self::exclusions( $value );

			// Retention deletes backups beyond these counts, so a value out of
			// range is refused rather than clamped: clamping would turn the
			// common "-1 = unlimited" or a typo into "keep almost nothing".
			case 'keep_local':
				$count = self::toInt( $value );
				if ( null === $count || $count < 0 || $count > self::MAX_KEEP_LOCAL ) {
					throw self::error( 'keep_local' );
				}
				return $count;

			case 'keep_remote':
				$count = self::toInt( $value );
				if ( null === $count || $count < self::MIN_KEEP_REMOTE || $count > self::MAX_KEEP_REMOTE ) {
					throw self::error( 'keep_remote' );
				}
				return $count;

			case 'notify_email':
				if ( ! is_string( $value ) ) {
					throw self::error( 'notify_email' );
				}
				$email = trim( $value );
				if ( '' !== $email && ! self::isEmail( $email ) ) {
					throw self::error( 'notify_email' );
				}
				return $email;
		}

		throw new \InvalidArgumentException( 'Unknown schedule field.' );
	}

	/**
	 * The fields nextRun() and describe() need, leniently normalised: they
	 * must never throw, so an invalid value reads as its default.
	 *
	 * @param array $config Configuration.
	 * @return array frequency, time, weekday, monthday.
	 */
	private static function timing( array $config ) {
		$defaults = self::defaults();
		$timing   = array();
		foreach ( array( 'frequency', 'time', 'weekday', 'monthday' ) as $key ) {
			$timing[ $key ] = $defaults[ $key ];
			if ( ! isset( $config[ $key ] ) ) {
				continue;
			}
			try {
				$timing[ $key ] = self::normalizeField( $key, $config[ $key ] );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}
		return $timing;
	}

	/**
	 * Unix time of a wall-clock time in a time zone.
	 *
	 * @param \DateTimeZone $tz     Time zone.
	 * @param int           $year   Year.
	 * @param int           $month  Month.
	 * @param int           $day    Day (valid for the month).
	 * @param int           $hour   Hour.
	 * @param int           $minute Minute.
	 * @return int
	 */
	private static function wallTime( \DateTimeZone $tz, $year, $month, $day, $hour, $minute ) {
		$wall = sprintf( '%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute );
		$php  = new \DateTimeImmutable( $wall . ':00', $tz );

		// Which occurrence PHP picks for an ambiguous wall time differs
		// between zones east and west of UTC, so every UTC offset in force
		// around that day is tried explicitly.
		$naive   = ( new \DateTimeImmutable( $wall . ':00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$offsets = array( $php->getOffset() );
		$changes = $tz->getTransitions( $naive - 2 * 86400, $naive + 2 * 86400 );
		if ( is_array( $changes ) ) {
			foreach ( $changes as $change ) {
				$offsets[] = (int) $change['offset'];
			}
		}

		$exact = null;
		$later = null;
		foreach ( array_unique( $offsets ) as $offset ) {
			$instant = $naive - $offset;
			$shown   = ( new \DateTimeImmutable( '@' . $instant ) )->setTimezone( $tz )->format( 'Y-m-d H:i' );
			if ( $shown === $wall ) {
				$exact = null === $exact ? $instant : min( $exact, $instant );
			} elseif ( $shown > $wall ) {
				$later = null === $later ? $instant : min( $later, $instant );
			}
		}

		if ( null !== $exact ) {
			return $exact;
		}
		// The wall time was skipped: the earliest instant shown after it is
		// the requested time moved forward by the length of the gap.
		return null !== $later ? $later : $php->getTimestamp();
	}

	/**
	 * Normalise the exclusion patterns.
	 *
	 * @param mixed $value String (one pattern per line or comma separated) or list.
	 * @return string[]
	 * @throws \InvalidArgumentException When the value is neither.
	 */
	private static function exclusions( $value ) {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			$value = array( (string) $value );
		}
		if ( ! is_array( $value ) ) {
			throw self::error( 'exclusions' );
		}

		$patterns = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) && ! is_int( $item ) && ! is_float( $item ) ) {
				continue;
			}
			foreach ( preg_split( '/[\r\n,]+/', (string) $item ) as $pattern ) {
				$pattern = self::cleanPattern( $pattern );
				if ( '' === $pattern || self::length( $pattern ) > self::MAX_PATTERN_LENGTH || in_array( $pattern, $patterns, true ) ) {
					continue;
				}
				$patterns[] = $pattern;
				if ( count( $patterns ) >= self::MAX_EXCLUSIONS ) {
					return $patterns;
				}
			}
		}
		return $patterns;
	}

	/**
	 * Clean one exclusion pattern.
	 *
	 * @param string $pattern Pattern.
	 * @return string Empty when nothing usable is left.
	 */
	private static function cleanPattern( $pattern ) {
		// Control characters (NUL above all) never belong in a path pattern.
		$pattern = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $pattern ) );
		if ( '' === $pattern ) {
			return '';
		}
		$pattern  = str_replace( '\\', '/', $pattern );
		$leading  = '/' === $pattern[0];
		$segments = array();
		foreach ( explode( '/', $pattern ) as $segment ) {
			// "." and ".." (and look-alikes such as "..." or ". .") never name
			// something inside the site; dropping them keeps a pattern from
			// pointing outside it.
			if ( '' === trim( $segment, " \t." ) ) {
				continue;
			}
			$segments[] = $segment;
		}
		if ( empty( $segments ) ) {
			return '';
		}
		// The leading slash is kept as the user wrote it, but it does not
		// anchor anything: ExclusionMatcher::add() strips it, so "/cache"
		// matches a directory named cache at any depth, like "cache".
		// The result is trimmed again because whitespace before a trailing
		// (back)slash only reaches the edge once empty segments are dropped;
		// without this a second sanitize() would change the value.
		return trim( ( $leading ? '/' : '' ) . implode( '/', $segments ) );
	}

	/**
	 * Length in characters.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Integer from an int, an integral float or a numeric string.
	 *
	 * @param mixed $value Value.
	 * @return int|null Null when not an integer.
	 */
	private static function toInt( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && abs( $value ) < 1e9 ) {
			return (int) $value;
		}
		// At most nine digits: larger values are nonsense here and would
		// overflow on 32-bit PHP.
		if ( is_string( $value ) && preg_match( '/^\s*([+-]?[0-9]{1,9})\s*\z/', $value, $match ) ) {
			return (int) $match[1];
		}
		return null;
	}

	/**
	 * Boolean from the spellings forms and APIs use.
	 *
	 * @param mixed $value Value.
	 * @return bool|null Null when not recognisable.
	 */
	private static function toBool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 0 === $value || 0.0 === $value ) {
			return false;
		}
		if ( 1 === $value || 1.0 === $value ) {
			return true;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, array( '1', 'true', 'on', 'yes' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( '0', 'false', 'off', 'no', '' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * Whether an e-mail address is valid.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	private static function isEmail( $email ) {
		if ( function_exists( 'is_email' ) ) {
			return false !== is_email( $email );
		}
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Whether WordPress translation functions are loaded.
	 *
	 * @return bool
	 */
	private static function canTranslate() {
		return function_exists( '__' );
	}

	/**
	 * Validation error with a message for the site owner.
	 *
	 * The strings are spelled out twice so that the translation tools find
	 * them and the class still works without WordPress.
	 *
	 * @param string $field Field (or rule) that failed.
	 * @return \InvalidArgumentException
	 */
	private static function error( $field ) {
		$wp = self::canTranslate();
		switch ( $field ) {
			case 'frequency':
				$message = $wp ? __( 'Choose how often backups run: only on demand, daily, weekly or monthly.', 'sh-clone-migration' ) : 'Choose how often backups run: only on demand, daily, weekly or monthly.';
				break;
			case 'time':
				$message = $wp ? __( 'Enter the backup time as HH:MM on a 24-hour clock (00:00 to 23:59).', 'sh-clone-migration' ) : 'Enter the backup time as HH:MM on a 24-hour clock (00:00 to 23:59).';
				break;
			case 'weekday':
				$message = $wp ? __( 'Choose the weekday for weekly backups.', 'sh-clone-migration' ) : 'Choose the weekday for weekly backups.';
				break;
			case 'monthday':
				$message = $wp ? __( 'Choose a day of the month from 1 to 28, or the last day of the month.', 'sh-clone-migration' ) : 'Choose a day of the month from 1 to 28, or the last day of the month.';
				break;
			case 'contents':
				$message = $wp ? __( 'Choose what to back up: the database and files, the database only, or the files only.', 'sh-clone-migration' ) : 'Choose what to back up: the database and files, the database only, or the files only.';
				break;
			case 'include_core':
				$message = $wp ? __( 'Choose whether to include the WordPress core files.', 'sh-clone-migration' ) : 'Choose whether to include the WordPress core files.';
				break;
			case 'gdrive':
				$message = $wp ? __( 'Choose whether to store backups on Google Drive.', 'sh-clone-migration' ) : 'Choose whether to store backups on Google Drive.';
				break;
			case 'encrypt':
				$message = $wp ? __( 'Choose whether to encrypt backups.', 'sh-clone-migration' ) : 'Choose whether to encrypt backups.';
				break;
			case 'exclusions':
				$message = $wp ? __( 'Enter the exclusions as a list of patterns, one per line.', 'sh-clone-migration' ) : 'Enter the exclusions as a list of patterns, one per line.';
				break;
			case 'keep_local':
				$message = $wp ? __( 'Enter how many backups to keep on this server (a number from 0 to 100).', 'sh-clone-migration' ) : 'Enter how many backups to keep on this server (a number from 0 to 100).';
				break;
			case 'keep_remote':
				$message = $wp ? __( 'Enter how many backups to keep on Google Drive (a number from 1 to 1000).', 'sh-clone-migration' ) : 'Enter how many backups to keep on Google Drive (a number from 1 to 1000).';
				break;
			case 'notify_on':
				$message = $wp ? __( 'Choose when to send e-mail: when a backup fails, after every backup, or never.', 'sh-clone-migration' ) : 'Choose when to send e-mail: when a backup fails, after every backup, or never.';
				break;
			case 'notify_email':
				$message = $wp ? __( 'Enter a valid e-mail address for notifications, or leave the field empty to use the site admin address.', 'sh-clone-migration' ) : 'Enter a valid e-mail address for notifications, or leave the field empty to use the site admin address.';
				break;
			case 'keep_none':
				$message = $wp ? __( 'Keep at least one backup on this server, or store backups on Google Drive.', 'sh-clone-migration' ) : 'Keep at least one backup on this server, or store backups on Google Drive.';
				break;
			default:
				$message = $wp ? __( 'The schedule settings are invalid.', 'sh-clone-migration' ) : 'The schedule settings are invalid.';
		}
		return new \InvalidArgumentException( $message );
	}
}
