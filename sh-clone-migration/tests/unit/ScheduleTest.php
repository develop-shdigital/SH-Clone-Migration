<?php
/**
 * Backup schedule model tests.
 *
 * @package SHCM
 */

namespace SHCM\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SHCM\Backup\Schedule;
use SHCM\Filesystem\ExclusionMatcher;

/**
 * Validation, next run times (time zones, daylight saving time, month
 * lengths) and summaries of the backup schedule.
 */
class ScheduleTest extends TestCase {

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Next run for a configuration, as an ISO 8601 string in the zone (the
	 * offset tells which occurrence of an ambiguous wall time it is).
	 *
	 * @param array  $config Partial configuration (merged over the defaults).
	 * @param string $zone   Time zone name.
	 * @param string $now    Current time, ISO 8601 with offset.
	 * @return string|null
	 */
	private function next( array $config, $zone, $now ) {
		$tz   = new \DateTimeZone( $zone );
		$next = Schedule::nextRun( array_merge( Schedule::defaults(), $config ), $tz, $this->ts( $now ) );
		if ( null === $next ) {
			return null;
		}
		$this->assertIsInt( $next );
		$this->assertGreaterThan( $this->ts( $now ), $next, 'nextRun must be strictly after $now' );
		return ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( $tz )->format( 'c' );
	}

	/**
	 * Unix time of an ISO 8601 string with offset.
	 *
	 * @param string $iso Time.
	 * @return int
	 */
	private function ts( $iso ) {
		return ( new \DateTimeImmutable( $iso ) )->getTimestamp();
	}

	/**
	 * Skip unless the time zone database resolves a wall time as the test
	 * expects (the rules of some zones changed in recent tzdata releases).
	 *
	 * @param string $zone     Time zone name.
	 * @param string $wall     Wall time "Y-m-d H:i".
	 * @param string $expected ISO 8601 result PHP must give.
	 * @return void
	 */
	private function requireWallTime( $zone, $wall, $expected ) {
		try {
			$actual = ( new \DateTimeImmutable( $wall, new \DateTimeZone( $zone ) ) )->format( 'c' );
		} catch ( \Exception $e ) {
			$actual = $e->getMessage();
		}
		if ( $expected !== $actual ) {
			$this->markTestSkipped( 'The time zone database has other rules for ' . $zone . ': ' . $wall . ' is ' . $actual . '.' );
		}
	}

	/**
	 * Run sanitize() and return the exception message, failing when it does not throw.
	 *
	 * @param array $input   Input.
	 * @param array $current Current configuration.
	 * @return string
	 */
	private function rejection( array $input, array $current = array() ) {
		try {
			Schedule::sanitize( $input, $current );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
			return $e->getMessage();
		}
		$this->fail( 'Expected an InvalidArgumentException for ' . var_export( $input, true ) );
	}

	/* ------------------------------------------------------------------
	 * defaults / sanitize
	 * ------------------------------------------------------------------ */

	public function testDefaults() {
		$this->assertSame(
			array(
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
			),
			Schedule::defaults()
		);
		$this->assertSame( array( 'manual', 'daily', 'weekly', 'monthly' ), Schedule::FREQUENCIES );
		$this->assertSame( Schedule::defaults(), Schedule::sanitize( array() ) );
	}

	public function testInputOverCurrentOverDefaults() {
		$current = Schedule::sanitize(
			array(
				'frequency' => 'weekly',
				'time'      => '05:15',
				'weekday'   => 3,
			)
		);
		$config  = Schedule::sanitize( array( 'time' => '06:45' ), $current );

		$this->assertSame( 'weekly', $config['frequency'] );
		$this->assertSame( '06:45', $config['time'] );
		$this->assertSame( 3, $config['weekday'] );
		$this->assertSame( 1, $config['monthday'] );
		$this->assertSame( array_keys( Schedule::defaults() ), array_keys( $config ) );
	}

	public function testUnknownKeysAndThePasswordAreDropped() {
		$config = Schedule::sanitize(
			array(
				'frequency' => 'daily',
				'password'  => 'secret',
				'evil'      => '<script>',
			),
			array( 'state' => array( 'x' => 1 ) )
		);
		$this->assertArrayNotHasKey( 'password', $config );
		$this->assertArrayNotHasKey( 'evil', $config );
		$this->assertArrayNotHasKey( 'state', $config );
		$this->assertSame( array_keys( Schedule::defaults() ), array_keys( $config ) );
	}

	public function testSanitizeIsIdempotent() {
		$config = Schedule::sanitize(
			array(
				'frequency'    => 'Monthly',
				'time'         => '7:05',
				'monthday'     => 'last',
				'contents'     => 'database',
				'include_core' => 'on',
				'exclusions'   => "*.log\r\nwp-content/cache",
				'keep_local'   => '0',
				'gdrive'       => '1',
				'keep_remote'  => '25',
				'encrypt'      => 'true',
				'notify_on'    => 'always',
				'notify_email' => ' owner@example.com ',
			)
		);
		$this->assertSame(
			array(
				'frequency'    => 'monthly',
				'time'         => '07:05',
				'weekday'      => 0,
				'monthday'     => -1,
				'contents'     => 'database',
				'include_core' => true,
				'exclusions'   => array( '*.log', 'wp-content/cache' ),
				'keep_local'   => 0,
				'gdrive'       => true,
				'keep_remote'  => 25,
				'encrypt'      => true,
				'notify_on'    => 'always',
				'notify_email' => 'owner@example.com',
			),
			$config
		);
		$this->assertSame( $config, Schedule::sanitize( $config ) );
		$this->assertSame( $config, Schedule::sanitize( array(), $config ) );
		$this->assertSame( $config, Schedule::sanitize( $config, Schedule::defaults() ) );
	}

	/**
	 * Whitespace in front of a trailing slash or backslash only reaches the
	 * edge of the pattern once the empty segment is dropped; it must be gone
	 * after the first sanitize(), not the second.
	 */
	public function testExclusionCleaningIsIdempotent() {
		$cases = array(
			"wp-content/cache /\nuploads\\ \\big" => array( 'wp-content/cache', 'uploads/big' ),
			'0- \\'                               => array( '0-' ),
			' a /'                                => array( 'a' ),
			// Only the edges of the whole pattern are trimmed, not each segment.
			'/ b / '                              => array( '/ b' ),
			"c \t/ ./"                            => array( 'c' ),
			'\\ d \\ .. \\'                       => array( '/ d' ),
			'\\d \\ '                             => array( '/d' ),
			'a / b'                               => array( 'a / b' ),
		);
		foreach ( $cases as $input => $expected ) {
			$config = Schedule::sanitize( array( 'exclusions' => $input ) );
			$this->assertSame( $expected, $config['exclusions'], var_export( $input, true ) );
			$this->assertSame( $config, Schedule::sanitize( $config ), var_export( $input, true ) );
			$this->assertSame( $config, Schedule::sanitize( array(), $config ), var_export( $input, true ) );
		}

		// Seeded fuzz over the characters that matter to the cleaning.
		$alphabet = array( '.', '..', '/', '\\', ',', "\n", "\r", "\0", ' ', "\t", 'a', 'b', '*', '?', 'ä', "\x7f", ':', '-', '0' );
		mt_srand( 42 );
		for ( $i = 0; $i < 3000; $i++ ) {
			$input  = '';
			$length = mt_rand( 0, 30 );
			for ( $j = 0; $j < $length; $j++ ) {
				$input .= $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ];
			}
			$once = Schedule::sanitize( array( 'exclusions' => $input ) )['exclusions'];
			$this->assertSame( $once, Schedule::sanitize( array( 'exclusions' => $once ) )['exclusions'], bin2hex( $input ) );
			foreach ( $once as $pattern ) {
				$this->assertSame( trim( $pattern ), $pattern, bin2hex( $input ) );
			}
		}
	}

	public function testFrequency() {
		foreach ( Schedule::FREQUENCIES as $frequency ) {
			$this->assertSame( $frequency, Schedule::sanitize( array( 'frequency' => $frequency ) )['frequency'] );
		}
		$this->assertSame( 'daily', Schedule::sanitize( array( 'frequency' => ' DAILY ' ) )['frequency'] );
		$this->assertSame( 'Choose how often backups run: only on demand, daily, weekly or monthly.', $this->rejection( array( 'frequency' => 'hourly' ) ) );
		$this->rejection( array( 'frequency' => 1 ) );
		$this->rejection( array( 'frequency' => array( 'daily' ) ) );
		$this->rejection( array( 'frequency' => true ) );
	}

	public function testEmptyOrNullMeansUnchangedForRequiredFields() {
		$current = Schedule::sanitize(
			array(
				'frequency'   => 'weekly',
				'time'        => '04:30',
				'weekday'     => 5,
				'keep_remote' => 7,
			)
		);
		$config  = Schedule::sanitize(
			array(
				'frequency'   => '',
				'time'        => '',
				'weekday'     => null,
				'monthday'    => '',
				'contents'    => '',
				'keep_local'  => '',
				'keep_remote' => null,
				'notify_on'   => '',
			),
			$current
		);
		$this->assertSame( $current, $config );
	}

	public function testTime() {
		$cases = array(
			'3:00'     => '03:00',
			'03:00'    => '03:00',
			'0:00'     => '00:00',
			'00:00'    => '00:00',
			'23:59'    => '23:59',
			'9:05'     => '09:05',
			' 12:30 '  => '12:30',
			"03:00\n"  => '03:00',
			'07:05:30' => '07:05',
		);
		foreach ( $cases as $input => $expected ) {
			$this->assertSame( $expected, Schedule::sanitize( array( 'time' => $input ) )['time'], (string) $input );
			$this->assertSame( $expected, Schedule::normalizeTime( $input ), (string) $input );
		}

		$this->assertSame( 'Enter the backup time as HH:MM on a 24-hour clock (00:00 to 23:59).', $this->rejection( array( 'time' => '24:00' ) ) );
		foreach ( array( '12:60', '3', '3:0', '12:5', '003:00', 'abc', '12-30', '12:30 pm', "03:00\nx", '-1:00', '1:00:60' ) as $invalid ) {
			$this->rejection( array( 'time' => $invalid ) );
			$this->assertNull( Schedule::normalizeTime( $invalid ), $invalid );
		}
		$this->rejection( array( 'time' => 3 ) );
		$this->rejection( array( 'time' => array( '03:00' ) ) );
		$this->assertNull( Schedule::normalizeTime( 300 ) );
		$this->assertNull( Schedule::normalizeTime( null ) );
	}

	public function testWeekday() {
		for ( $day = 0; $day <= 6; $day++ ) {
			$this->assertSame( $day, Schedule::sanitize( array( 'weekday' => $day ) )['weekday'] );
			$this->assertSame( $day, Schedule::sanitize( array( 'weekday' => (string) $day ) )['weekday'] );
		}
		$this->assertSame( 2, Schedule::sanitize( array( 'weekday' => 2.0 ) )['weekday'] );
		$this->assertSame( 'Choose the weekday for weekly backups.', $this->rejection( array( 'weekday' => 7 ) ) );
		foreach ( array( -1, '7', 'Sunday', '1.5', 1.5, true, array( 1 ) ) as $invalid ) {
			$this->rejection( array( 'weekday' => $invalid ) );
		}
	}

	public function testMonthday() {
		for ( $day = 1; $day <= 28; $day++ ) {
			$this->assertSame( $day, Schedule::sanitize( array( 'monthday' => (string) $day ) )['monthday'] );
		}
		$this->assertSame( -1, Schedule::sanitize( array( 'monthday' => -1 ) )['monthday'] );
		$this->assertSame( -1, Schedule::sanitize( array( 'monthday' => '-1' ) )['monthday'] );
		$this->assertSame( -1, Schedule::sanitize( array( 'monthday' => 'last' ) )['monthday'] );
		$this->assertSame( -1, Schedule::sanitize( array( 'monthday' => ' LAST ' ) )['monthday'] );
		$this->assertSame( Schedule::LAST_DAY, -1 );

		$this->assertSame( 'Choose a day of the month from 1 to 28, or the last day of the month.', $this->rejection( array( 'monthday' => 29 ) ) );
		foreach ( array( 0, 31, -2, '30', 'first', true ) as $invalid ) {
			$this->rejection( array( 'monthday' => $invalid ) );
		}
	}

	public function testContents() {
		foreach ( array( 'full', 'database', 'files' ) as $contents ) {
			$this->assertSame( $contents, Schedule::sanitize( array( 'contents' => $contents ) )['contents'] );
		}
		$this->assertSame( 'Choose what to back up: the database and files, the database only, or the files only.', $this->rejection( array( 'contents' => 'uploads' ) ) );
	}

	public function testBooleans() {
		$true  = array( true, 1, '1', 'true', 'TRUE', 'on', 'yes', ' on ' );
		$false = array( false, 0, '0', 'false', 'off', 'no', '' );
		foreach ( array( 'include_core', 'gdrive', 'encrypt' ) as $field ) {
			foreach ( $true as $value ) {
				$config = Schedule::sanitize( array( $field => $value ) );
				$this->assertTrue( $config[ $field ], $field . ' ' . var_export( $value, true ) );
			}
			foreach ( $false as $value ) {
				// Start from true so that a false result is not the default.
				$config = Schedule::sanitize( array( $field => $value ), array( $field => true ) );
				$this->assertFalse( $config[ $field ], $field . ' ' . var_export( $value, true ) );
			}
			foreach ( array( 'maybe', 2, -1, array( true ), '1.0' ) as $invalid ) {
				$this->rejection( array( $field => $invalid ) );
			}
			// Null means "not sent".
			$this->assertTrue( Schedule::sanitize( array( $field => null ), array( $field => true ) )[ $field ] );
		}
	}

	public function testExclusionsFromTextAndLists() {
		$config = Schedule::sanitize( array( 'exclusions' => " *.log \r\nwp-content/cache,, node_modules\n\n\twp-content/uploads/videos \n" ) );
		$this->assertSame( array( '*.log', 'wp-content/cache', 'node_modules', 'wp-content/uploads/videos' ), $config['exclusions'] );

		$config = Schedule::sanitize( array( 'exclusions' => array( ' a ', '', 'b,c', "d\ne", array( 'nested' ), null, 7 ) ) );
		$this->assertSame( array( 'a', 'b', 'c', 'd', 'e', '7' ), $config['exclusions'] );

		// Duplicates collapse.
		$config = Schedule::sanitize( array( 'exclusions' => "a\na\n a" ) );
		$this->assertSame( array( 'a' ), $config['exclusions'] );

		// An empty string clears the list; null keeps it.
		$current = Schedule::sanitize( array( 'exclusions' => 'x' ) );
		$this->assertSame( array(), Schedule::sanitize( array( 'exclusions' => '' ), $current )['exclusions'] );
		$this->assertSame( array(), Schedule::sanitize( array( 'exclusions' => array() ), $current )['exclusions'] );
		$this->assertSame( array( 'x' ), Schedule::sanitize( array( 'exclusions' => null ), $current )['exclusions'] );

		$this->assertSame( 'Enter the exclusions as a list of patterns, one per line.', $this->rejection( array( 'exclusions' => true ) ) );
		$this->rejection( array( 'exclusions' => new \stdClass() ) );
	}

	public function testExclusionsCannotEscapeTheSite() {
		$cases = array(
			'../wp-config.php'           => 'wp-config.php',
			'wp-content/../../etc'       => 'wp-content/etc',
			'/wp-content/../cache/'      => '/wp-content/cache',
			'./uploads'                  => 'uploads',
			'wp-content\\..\\cache'      => 'wp-content/cache',
			"wp-content/ca\0che"         => 'wp-content/cache',
			'wp-content//cache'          => 'wp-content/cache',
			'.../x'                      => 'x',
			'*/node_modules'             => '*/node_modules',
			'..hidden/file..txt'         => '..hidden/file..txt',
			'/wp-content/cache'          => '/wp-content/cache',
		);
		foreach ( $cases as $input => $expected ) {
			$this->assertSame( array( $expected ), Schedule::sanitize( array( 'exclusions' => array( $input ) ) )['exclusions'], $input );
		}
		foreach ( array( '..', '../..', '/', '.', "\0", ' / .. / ' ) as $nothing ) {
			$this->assertSame( array(), Schedule::sanitize( array( 'exclusions' => array( $nothing ) ) )['exclusions'], $nothing );
		}
	}

	/**
	 * A leading slash is kept as typed, but ExclusionMatcher strips it, so it
	 * does not restrict a pattern to the root. The schedule must not promise
	 * otherwise (see the comment in Schedule::cleanPattern()).
	 */
	public function testLeadingSlashIsKeptButDoesNotAnchor() {
		$this->assertSame( array( '/cache' ), Schedule::sanitize( array( 'exclusions' => '/cache' ) )['exclusions'] );
		$matcher = new ExclusionMatcher( array( '/cache' ) );
		$this->assertSame( array( 'cache' ), $matcher->patterns() );
		$this->assertTrue( $matcher->matches( 'cache' ) );
		$this->assertTrue( $matcher->matches( 'wp-content/plugins/x/cache' ) );
	}

	public function testExclusionLimits() {
		$many = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$many[] = 'dir-' . $i;
		}
		$config = Schedule::sanitize( array( 'exclusions' => implode( "\n", $many ) ) );
		$this->assertCount( 100, $config['exclusions'] );
		$this->assertSame( array_slice( $many, 0, 100 ), $config['exclusions'] );

		$ok     = str_repeat( 'a', 255 );
		$long   = str_repeat( 'b', 256 );
		$umlaut = str_repeat( 'ä', 255 );
		$config = Schedule::sanitize( array( 'exclusions' => array( $ok, $long, $umlaut ) ) );
		$this->assertSame( array( $ok, $umlaut ), $config['exclusions'] );
	}

	public function testKeepCounts() {
		$this->assertSame( 5, Schedule::sanitize( array( 'keep_local' => '5' ) )['keep_local'] );
		$this->assertSame( 100, Schedule::sanitize( array( 'keep_local' => 100 ) )['keep_local'] );
		$this->assertSame( 1, Schedule::sanitize( array( 'keep_local' => ' 1 ' ) )['keep_local'] );
		$this->assertSame( 0, Schedule::sanitize( array( 'keep_local' => '0', 'gdrive' => true ) )['keep_local'] );
		$message = 'Enter how many backups to keep on this server (a number from 0 to 100).';
		$this->assertSame( $message, $this->rejection( array( 'keep_local' => 'many' ) ) );
		foreach ( array( '2.5', true, '99999999999', 2.5 ) as $invalid ) {
			$this->assertSame( $message, $this->rejection( array( 'keep_local' => $invalid ) ) );
		}

		$this->assertSame( 20, Schedule::sanitize( array( 'keep_remote' => '20' ) )['keep_remote'] );
		$this->assertSame( 1, Schedule::sanitize( array( 'keep_remote' => 1 ) )['keep_remote'] );
		$this->assertSame( 1000, Schedule::sanitize( array( 'keep_remote' => '1000' ) )['keep_remote'] );
		$this->assertSame( 'Enter how many backups to keep on Google Drive (a number from 1 to 1000).', $this->rejection( array( 'keep_remote' => 'x' ) ) );
	}

	/**
	 * Retention deletes backups beyond these counts, so a count out of range
	 * (the usual "-1 = unlimited", 0 for Drive, a typo) must be refused with
	 * the field's message, never clamped to "keep almost nothing".
	 */
	public function testKeepCountsOutOfRangeAreRefusedNotClamped() {
		$local = 'Enter how many backups to keep on this server (a number from 0 to 100).';
		foreach ( array( 150, 101, '101', -3, -1, '-1', '-100', ' -5 ' ) as $invalid ) {
			// With Drive on, so that the cross-field rule cannot be the reason.
			$this->assertSame( $local, $this->rejection( array( 'keep_local' => $invalid, 'gdrive' => true ) ), var_export( $invalid, true ) );
		}

		$remote = 'Enter how many backups to keep on Google Drive (a number from 1 to 1000).';
		foreach ( array( 0, '0', -1, '-5', 1001, 5000, '5000' ) as $invalid ) {
			$this->assertSame( $remote, $this->rejection( array( 'keep_remote' => $invalid, 'gdrive' => true ) ), var_export( $invalid, true ) );
		}

		// A damaged stored value still falls back to the default (which
		// keeps more than the clamped value would have) instead of throwing.
		$config = Schedule::sanitize(
			array(),
			array(
				'keep_local'  => -1,
				'keep_remote' => 0,
				'gdrive'      => true,
			)
		);
		$this->assertSame( 3, $config['keep_local'] );
		$this->assertSame( 10, $config['keep_remote'] );
		$config = Schedule::sanitize( array(), array( 'keep_local' => 150, 'keep_remote' => 5000 ) );
		$this->assertSame( 3, $config['keep_local'] );
		$this->assertSame( 10, $config['keep_remote'] );
	}

	public function testNoLocalCopiesOnlyWithGoogleDrive() {
		$message = 'Keep at least one backup on this server, or store backups on Google Drive.';
		$this->assertSame( $message, $this->rejection( array( 'keep_local' => 0 ) ) );
		$this->assertSame( $message, $this->rejection( array( 'keep_local' => '0', 'gdrive' => false ) ) );
		// -1 is out of range and refused as such (it no longer becomes 0).
		$this->assertSame( 'Enter how many backups to keep on this server (a number from 0 to 100).', $this->rejection( array( 'keep_local' => -1 ) ) );

		$config = Schedule::sanitize( array( 'keep_local' => 0, 'gdrive' => true ) );
		$this->assertSame( 0, $config['keep_local'] );
		$this->assertTrue( $config['gdrive'] );

		// Turning Drive off while no local copies are kept is refused too.
		$this->assertSame( $message, $this->rejection( array( 'gdrive' => false ), $config ) );
		// Drive coming from the current configuration is enough.
		$this->assertSame( 0, Schedule::sanitize( array( 'keep_local' => 0 ), array( 'gdrive' => true ) )['keep_local'] );
	}

	public function testNotifications() {
		foreach ( array( 'failure', 'always', 'never' ) as $when ) {
			$this->assertSame( $when, Schedule::sanitize( array( 'notify_on' => $when ) )['notify_on'] );
		}
		$this->assertSame( 'Choose when to send e-mail: when a backup fails, after every backup, or never.', $this->rejection( array( 'notify_on' => 'sometimes' ) ) );

		$this->assertSame( 'owner@example.com', Schedule::sanitize( array( 'notify_email' => ' owner@example.com ' ) )['notify_email'] );
		$this->assertSame( '', Schedule::sanitize( array( 'notify_email' => '' ), array( 'notify_email' => 'a@example.com' ) )['notify_email'] );
		$this->assertSame( 'a@example.com', Schedule::sanitize( array( 'notify_email' => null ), array( 'notify_email' => 'a@example.com' ) )['notify_email'] );
		$message = 'Enter a valid e-mail address for notifications, or leave the field empty to use the site admin address.';
		$this->assertSame( $message, $this->rejection( array( 'notify_email' => 'not-an-email' ) ) );
		foreach ( array( 'a@', '@example.com', "a@example.com\nBcc: x@example.com", array( 'a@example.com' ), 5 ) as $invalid ) {
			$this->assertSame( $message, $this->rejection( array( 'notify_email' => $invalid ) ) );
		}
	}

	public function testDamagedStoredValuesFallBackToTheirDefaults() {
		$current = array(
			'frequency'  => 'hourly',
			'time'       => '25:00',
			'weekday'    => 4,
			'monthday'   => 31,
			'keep_local' => 'lots',
			'exclusions' => 'wp-content/cache',
			'gdrive'     => 'perhaps',
		);
		$config  = Schedule::sanitize( array(), $current );
		$this->assertSame( 'manual', $config['frequency'] );
		$this->assertSame( '03:00', $config['time'] );
		$this->assertSame( 4, $config['weekday'] );
		$this->assertSame( 1, $config['monthday'] );
		$this->assertSame( 3, $config['keep_local'] );
		$this->assertFalse( $config['gdrive'] );
		$this->assertSame( array( 'wp-content/cache' ), $config['exclusions'] );

		// Submitted values are still strict.
		$this->rejection( array( 'frequency' => 'hourly' ), $current );
	}

	/* ------------------------------------------------------------------
	 * nextRun: basics
	 * ------------------------------------------------------------------ */

	public function testManualHasNoNextRun() {
		$this->assertNull( Schedule::nextRun( Schedule::defaults(), new \DateTimeZone( 'UTC' ), time() ) );
		$this->assertNull( Schedule::nextRun( array( 'frequency' => 'hourly' ), new \DateTimeZone( 'UTC' ), time() ) );
		$this->assertNull( Schedule::nextRun( array(), new \DateTimeZone( 'UTC' ), time() ) );
	}

	public function testDailyUtc() {
		$daily = array( 'frequency' => 'daily' );
		$this->assertSame( '2026-09-29T03:00:00+00:00', $this->next( $daily, 'UTC', '2026-09-28T10:00:00+00:00' ) );
		$this->assertSame( '2026-09-28T03:00:00+00:00', $this->next( $daily, 'UTC', '2026-09-28T02:59:59+00:00' ) );
		$this->assertSame( '2026-09-28T03:00:00+00:00', $this->next( $daily, 'UTC', '2026-09-28T00:00:00+00:00' ) );
		$this->assertSame( '2027-01-01T03:00:00+00:00', $this->next( $daily, 'UTC', '2026-12-31T23:30:00+00:00' ) );
		$this->assertSame( '2026-09-29T00:00:00+00:00', $this->next( array( 'frequency' => 'daily', 'time' => '00:00' ), 'UTC', '2026-09-28T23:59:59+00:00' ) );
		$this->assertSame( '2026-09-28T23:59:00+00:00', $this->next( array( 'frequency' => 'daily', 'time' => '23:59' ), 'UTC', '2026-09-28T00:00:00+00:00' ) );
	}

	public function testExactlyNowMeansTheNextPeriod() {
		$this->assertSame( '2026-09-29T03:00:00+00:00', $this->next( array( 'frequency' => 'daily' ), 'UTC', '2026-09-28T03:00:00+00:00' ) );
		$this->assertSame( '2026-09-29T03:00:00+00:00', $this->next( array( 'frequency' => 'daily' ), 'UTC', '2026-09-28T03:00:01+00:00' ) );
		$this->assertSame( '2026-10-04T03:00:00+00:00', $this->next( array( 'frequency' => 'weekly', 'weekday' => 0 ), 'UTC', '2026-09-27T03:00:00+00:00' ) );
		$this->assertSame( '2026-11-01T03:00:00+00:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => 1 ), 'UTC', '2026-10-01T03:00:00+00:00' ) );
		$this->assertSame( '2026-10-31T03:00:00+00:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => -1 ), 'UTC', '2026-09-30T03:00:00+00:00' ) );
	}

	public function testFarEastZone() {
		$daily = array( 'frequency' => 'daily' );
		// 22:00 UTC on the 28th is 03:30 on the 29th in India.
		$this->assertSame( '2026-09-30T03:00:00+05:30', $this->next( $daily, 'Asia/Kolkata', '2026-09-28T22:00:00+00:00' ) );
		// 20:00 UTC on the 28th is 01:30 on the 29th: still today, local time.
		$this->assertSame( '2026-09-29T03:00:00+05:30', $this->next( $daily, 'Asia/Kolkata', '2026-09-28T20:00:00+00:00' ) );
		// Local Sunday 01:30 while it is still Saturday in UTC.
		$this->assertSame( '2026-10-04T03:00:00+05:30', $this->next( array( 'frequency' => 'weekly', 'weekday' => 0 ), 'Asia/Kolkata', '2026-10-03T20:00:00+00:00' ) );
		$this->assertSame( '2026-10-10T23:00:00+05:30', $this->next( array( 'frequency' => 'weekly', 'weekday' => 6, 'time' => '23:00' ), 'Asia/Kolkata', '2026-10-03T20:00:00+00:00' ) );
		// Local 1 November 02:00 while it is still 31 October in UTC.
		$this->assertSame( '2026-11-01T03:00:00+05:30', $this->next( array( 'frequency' => 'monthly', 'monthday' => 1 ), 'Asia/Kolkata', '2026-10-31T20:30:00+00:00' ) );
	}

	public function testNegativeOffsetZone() {
		// 02:00 UTC on the 28th is 22:00 on Sunday the 27th in New York.
		$this->assertSame( '2026-09-27T23:00:00-04:00', $this->next( array( 'frequency' => 'daily', 'time' => '23:00' ), 'America/New_York', '2026-09-28T02:00:00+00:00' ) );
		$this->assertSame( '2026-09-27T23:00:00-04:00', $this->next( array( 'frequency' => 'weekly', 'weekday' => 0, 'time' => '23:00' ), 'America/New_York', '2026-09-28T02:00:00+00:00' ) );
		$this->assertSame( '2026-09-30T23:00:00-04:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => -1, 'time' => '23:00' ), 'America/New_York', '2026-10-01T02:00:00+00:00' ) );
	}

	public function testFixedOffsetZone() {
		// wp_timezone() returns such a zone when the site uses "UTC+5:30".
		$this->assertSame( '2026-09-30T03:00:00+05:30', $this->next( array( 'frequency' => 'daily' ), '+05:30', '2026-09-28T22:00:00+00:00' ) );
		$this->assertSame( '2026-09-29T03:00:00+05:30', $this->next( array( 'frequency' => 'daily' ), '+05:30', '2026-09-28T20:00:00+00:00' ) );
		$this->assertSame( '2026-09-28T03:00:00-03:00', $this->next( array( 'frequency' => 'daily' ), '-03:00', '2026-09-28T05:59:00+00:00' ) );
	}

	/* ------------------------------------------------------------------
	 * nextRun: daylight saving time
	 * ------------------------------------------------------------------ */

	public function testSpringForwardGapMovesForward() {
		$daily = array( 'frequency' => 'daily', 'time' => '02:30' );
		// 29 March 2026: Zurich jumps from 02:00 CET to 03:00 CEST.
		$this->assertSame( '2026-03-29T03:30:00+02:00', $this->next( $daily, 'Europe/Zurich', '2026-03-29T01:00:00+01:00' ) );
		$this->assertSame( '2026-03-29T03:30:00+02:00', $this->next( $daily, 'Europe/Zurich', '2026-03-28T02:30:00+01:00' ) );
		$this->assertSame( '2026-03-29T03:30:00+02:00', $this->next( $daily, 'Europe/Zurich', '2026-03-29T03:10:00+02:00' ) );
		$this->assertSame( '2026-03-30T02:30:00+02:00', $this->next( $daily, 'Europe/Zurich', '2026-03-29T03:30:00+02:00' ) );

		$this->assertSame( '2026-03-29T03:00:00+02:00', $this->next( array( 'frequency' => 'daily', 'time' => '02:00' ), 'Europe/Zurich', '2026-03-29T00:00:00+01:00' ) );
		$this->assertSame( '2026-03-29T03:59:00+02:00', $this->next( array( 'frequency' => 'daily', 'time' => '02:59' ), 'Europe/Zurich', '2026-03-29T00:00:00+01:00' ) );
		$this->assertSame( '2026-03-29T03:00:00+02:00', $this->next( array( 'frequency' => 'daily', 'time' => '03:00' ), 'Europe/Zurich', '2026-03-29T00:00:00+01:00' ) );
		$this->assertSame( '2026-03-29T01:59:00+01:00', $this->next( array( 'frequency' => 'daily', 'time' => '01:59' ), 'Europe/Zurich', '2026-03-29T00:00:00+01:00' ) );

		// Weekly on Sunday and monthly on day 28 hit the gap too (28 March 2027 is the switch).
		$this->assertSame( '2026-03-29T03:30:00+02:00', $this->next( array( 'frequency' => 'weekly', 'weekday' => 0, 'time' => '02:30' ), 'Europe/Zurich', '2026-03-22T12:00:00+01:00' ) );
		$this->assertSame( '2027-03-28T03:30:00+02:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => 28, 'time' => '02:30' ), 'Europe/Zurich', '2027-03-01T12:00:00+01:00' ) );

		// New York, 8 March 2026: 02:00 EST becomes 03:00 EDT.
		$this->assertSame( '2026-03-08T03:30:00-04:00', $this->next( $daily, 'America/New_York', '2026-03-08T00:00:00-05:00' ) );
	}

	public function testFallBackTakesTheFirstOccurrenceOnce() {
		$daily = array( 'frequency' => 'daily', 'time' => '02:30' );
		// 25 October 2026: Zurich goes from 03:00 CEST back to 02:00 CET, so 02:30 happens twice.
		$this->assertSame( '2026-10-25T02:30:00+02:00', $this->next( $daily, 'Europe/Zurich', '2026-10-25T01:00:00+02:00' ) );
		$this->assertSame( '2026-10-25T02:30:00+02:00', $this->next( $daily, 'Europe/Zurich', '2026-10-24T02:30:00+02:00' ) );
		// Right after the first 02:30 run: tomorrow, not an hour later.
		$this->assertSame( '2026-10-26T02:30:00+01:00', $this->next( $daily, 'Europe/Zurich', '2026-10-25T02:30:00+02:00' ) );
		$this->assertSame( '2026-10-26T02:30:00+01:00', $this->next( $daily, 'Europe/Zurich', '2026-10-25T02:45:00+02:00' ) );
		// In the repeated hour, the first 02:30 has already passed.
		$this->assertSame( '2026-10-26T02:30:00+01:00', $this->next( $daily, 'Europe/Zurich', '2026-10-25T02:15:00+01:00' ) );
		$this->assertSame( '2026-10-26T02:30:00+01:00', $this->next( $daily, 'Europe/Zurich', '2026-10-25T02:45:00+01:00' ) );

		// Last day of October 2027 is the switch day.
		$this->assertSame( '2027-10-31T02:30:00+02:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => -1, 'time' => '02:30' ), 'Europe/Zurich', '2027-10-15T12:00:00+02:00' ) );
		$this->assertSame( '2027-11-30T02:30:00+01:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => -1, 'time' => '02:30' ), 'Europe/Zurich', '2027-10-31T02:30:00+02:00' ) );

		// New York, 1 November 2026: 01:30 happens twice (EDT, then EST).
		$ny = array( 'frequency' => 'daily', 'time' => '01:30' );
		$this->assertSame( '2026-11-01T01:30:00-04:00', $this->next( $ny, 'America/New_York', '2026-11-01T00:00:00-04:00' ) );
		$this->assertSame( '2026-11-02T01:30:00-05:00', $this->next( $ny, 'America/New_York', '2026-11-01T01:30:00-04:00' ) );
	}

	/**
	 * A chain of runs (each next run computed at the previous one) across
	 * both switches runs exactly once per calendar day.
	 */
	public function testDailyChainAcrossDaylightSavingSwitches() {
		$tz     = new \DateTimeZone( 'Europe/Zurich' );
		$config = Schedule::sanitize( array( 'frequency' => 'daily', 'time' => '02:30' ) );

		$expected = array(
			'2026-03-27T02:30:00+01:00',
			'2026-03-28T02:30:00+01:00',
			'2026-03-29T03:30:00+02:00',
			'2026-03-30T02:30:00+02:00',
			'2026-03-31T02:30:00+02:00',
		);
		$now      = $this->ts( '2026-03-26T12:00:00+01:00' );
		foreach ( $expected as $iso ) {
			$now = Schedule::nextRun( $config, $tz, $now );
			$this->assertSame( $iso, ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'c' ) );
		}

		$expected = array(
			'2026-10-23T02:30:00+02:00',
			'2026-10-24T02:30:00+02:00',
			'2026-10-25T02:30:00+02:00',
			'2026-10-26T02:30:00+01:00',
			'2026-10-27T02:30:00+01:00',
		);
		$now      = $this->ts( '2026-10-22T12:00:00+02:00' );
		foreach ( $expected as $iso ) {
			$now = Schedule::nextRun( $config, $tz, $now );
			$this->assertSame( $iso, ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'c' ) );
		}
	}

	/**
	 * When a gap crosses local midnight, the run of the previous day (or
	 * month) moves forward into today. Between midnight and that moved time
	 * it is still pending and must not be skipped.
	 */
	public function testGapAcrossMidnightKeepsThePendingRun() {
		// Since 2024 America/Nuuk jumps from Saturday 23:00 (-02) to Sunday 00:00 (-01).
		$this->requireWallTime( 'America/Nuuk', '2027-03-27 23:30', '2027-03-28T00:30:00-01:00' );
		$weekly = array( 'frequency' => 'weekly', 'weekday' => 6, 'time' => '23:30' );
		$daily  = array( 'frequency' => 'daily', 'time' => '23:30' );
		foreach ( array( '2027-03-27T22:00:00-02:00', '2027-03-28T00:00:00-01:00', '2027-03-28T00:10:00-01:00', '2027-03-28T00:29:59-01:00' ) as $now ) {
			$this->assertSame( '2027-03-28T00:30:00-01:00', $this->next( $weekly, 'America/Nuuk', $now ), $now );
			$this->assertSame( '2027-03-28T00:30:00-01:00', $this->next( $daily, 'America/Nuuk', $now ), $now );
		}
		$this->assertSame( '2027-04-03T23:30:00-01:00', $this->next( $weekly, 'America/Nuuk', '2027-03-28T00:30:00-01:00' ) );
		$this->assertSame( '2027-03-28T23:30:00-01:00', $this->next( $daily, 'America/Nuuk', '2027-03-28T00:30:00-01:00' ) );

		$this->requireWallTime( 'America/Scoresbysund', '2029-03-24 23:20', '2029-03-25T00:20:00-01:00' );
		$this->assertSame( '2029-03-25T00:20:00-01:00', $this->next( array( 'frequency' => 'daily', 'time' => '23:20' ), 'America/Scoresbysund', '2029-03-25T00:00:00-01:00' ) );

		// Monthly: the last day of the previous month moves into the 1st.
		// Sofia, 31 March 1979: 23:00 (+02) became 1 April 00:00 (+03).
		$this->requireWallTime( 'Europe/Sofia', '1979-03-31 23:30', '1979-04-01T00:30:00+03:00' );
		$last = array( 'frequency' => 'monthly', 'monthday' => -1, 'time' => '23:30' );
		$this->assertSame( '1979-04-01T00:30:00+03:00', $this->next( $last, 'Europe/Sofia', '1979-04-01T00:10:00+03:00' ) );
		$this->assertSame( '1979-04-30T23:30:00+03:00', $this->next( $last, 'Europe/Sofia', '1979-04-01T00:30:00+03:00' ) );
		// Singapore, 31 December 1981: 23:30 (+07:30) became 1 January 1982 00:00 (+08), across the year too.
		$this->requireWallTime( 'Asia/Singapore', '1981-12-31 23:45', '1982-01-01T00:15:00+08:00' );
		$this->assertSame( '1982-01-01T00:15:00+08:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => -1, 'time' => '23:45' ), 'Asia/Singapore', '1982-01-01T00:05:00+08:00' ) );
	}

	/* ------------------------------------------------------------------
	 * nextRun: weekly and monthly
	 * ------------------------------------------------------------------ */

	public function testWeekly() {
		$sunday = array( 'frequency' => 'weekly', 'weekday' => 0 );
		// 27 September 2026 is a Sunday.
		$this->assertSame( '2026-10-04T03:00:00+00:00', $this->next( $sunday, 'UTC', '2026-09-27T04:00:00+00:00' ) );
		$this->assertSame( '2026-09-27T03:00:00+00:00', $this->next( $sunday, 'UTC', '2026-09-27T02:00:00+00:00' ) );
		$this->assertSame( '2026-09-27T03:00:00+00:00', $this->next( $sunday, 'UTC', '2026-09-26T23:00:00+00:00' ) );
		$this->assertSame( '2026-10-03T03:00:00+00:00', $this->next( array( 'frequency' => 'weekly', 'weekday' => 6 ), 'UTC', '2026-09-27T04:00:00+00:00' ) );
		$this->assertSame( '2026-09-30T03:00:00+00:00', $this->next( array( 'frequency' => 'weekly', 'weekday' => '3' ), 'UTC', '2026-09-28T12:00:00+00:00' ) );
		// Across the end of the year.
		$this->assertSame( '2027-01-03T03:00:00+00:00', $this->next( $sunday, 'UTC', '2026-12-30T12:00:00+00:00' ) );
	}

	public function testMonthlyDays() {
		$first = array( 'frequency' => 'monthly', 'monthday' => 1 );
		$this->assertSame( '2026-10-01T03:00:00+00:00', $this->next( $first, 'UTC', '2026-09-28T12:00:00+00:00' ) );
		$this->assertSame( '2027-01-15T03:00:00+00:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => 15 ), 'UTC', '2026-12-20T12:00:00+00:00' ) );
		$this->assertSame( '2026-12-15T03:00:00+00:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => 15 ), 'UTC', '2026-12-15T02:00:00+00:00' ) );

		$day28 = array( 'frequency' => 'monthly', 'monthday' => 28 );
		$this->assertSame( '2027-02-28T03:00:00+00:00', $this->next( $day28, 'UTC', '2027-02-10T12:00:00+00:00' ) );
		$this->assertSame( '2028-02-28T03:00:00+00:00', $this->next( $day28, 'UTC', '2028-02-10T12:00:00+00:00' ) );
		$this->assertSame( '2028-03-28T03:00:00+00:00', $this->next( $day28, 'UTC', '2028-02-28T03:00:00+00:00' ) );
	}

	public function testMonthlyLastDay() {
		$last = array( 'frequency' => 'monthly', 'monthday' => -1 );
		$this->assertSame( '2027-02-28T03:00:00+00:00', $this->next( $last, 'UTC', '2027-02-10T12:00:00+00:00' ) );
		$this->assertSame( '2028-02-29T03:00:00+00:00', $this->next( $last, 'UTC', '2028-02-10T12:00:00+00:00' ) );
		$this->assertSame( '2028-02-29T03:00:00+00:00', $this->next( $last, 'UTC', '2028-02-28T12:00:00+00:00' ) );
		$this->assertSame( '2026-09-30T03:00:00+00:00', $this->next( $last, 'UTC', '2026-09-10T12:00:00+00:00' ) );
		$this->assertSame( '2026-10-31T03:00:00+00:00', $this->next( $last, 'UTC', '2026-09-30T04:00:00+00:00' ) );
		$this->assertSame( '2026-02-28T03:00:00+00:00', $this->next( $last, 'UTC', '2026-01-31T04:00:00+00:00' ) );
		$this->assertSame( '2027-01-31T03:00:00+00:00', $this->next( $last, 'UTC', '2026-12-31T04:00:00+00:00' ) );
		$this->assertSame( '2026-11-30T03:00:00+00:00', $this->next( array( 'frequency' => 'monthly', 'monthday' => 'last' ), 'UTC', '2026-11-01T00:00:00+00:00' ) );
	}

	/**
	 * Properties over many random instants and every instant around a
	 * clock change: the result is strictly in the future, within one period,
	 * shows the configured wall time (or the time just after a skipped
	 * hour), and no earlier occurrence exists: asking again at any moment
	 * between $now and the result (in particular at each local midnight in
	 * between) gives the same result.
	 *
	 * @param string $zone Time zone.
	 */
	#[DataProvider( 'zones' )]
	public function testNextRunProperties( $zone ) {
		try {
			$tz = new \DateTimeZone( $zone );
		} catch ( \Exception $e ) {
			$this->markTestSkipped( 'Unknown time zone ' . $zone . '.' );
		}
		$configs = array(
			array( 'frequency' => 'daily', 'time' => '02:30' ),
			array( 'frequency' => 'daily', 'time' => '00:00' ),
			array( 'frequency' => 'daily', 'time' => '23:30' ),
			array( 'frequency' => 'weekly', 'weekday' => 0, 'time' => '02:30' ),
			array( 'frequency' => 'weekly', 'weekday' => 4, 'time' => '23:59' ),
			array( 'frequency' => 'weekly', 'weekday' => 6, 'time' => '23:30' ),
			array( 'frequency' => 'monthly', 'monthday' => 28, 'time' => '01:30' ),
			array( 'frequency' => 'monthly', 'monthday' => 28, 'time' => '23:30' ),
			array( 'frequency' => 'monthly', 'monthday' => -1, 'time' => '02:30' ),
		);
		$limits  = array(
			'daily'   => 25 * 3600,
			'weekly'  => 7 * 86400 + 3600,
			'monthly' => 31 * 86400 + 3600,
		);

		mt_srand( crc32( $zone ) );
		$start = $this->ts( '2026-01-01T00:00:00+00:00' );
		$end   = $this->ts( '2028-12-31T00:00:00+00:00' );

		// Random instants, plus instants just before, at and after each
		// clock change, where the bugs live.
		$instants = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$instants[] = mt_rand( $start, $end );
		}
		$changes = $tz->getTransitions( $start, $end );
		foreach ( is_array( $changes ) ? array_slice( $changes, 1 ) : array() as $change ) {
			foreach ( array( -86400, -3600, -1, 0, 1, 600, 3600 ) as $delta ) {
				$instants[] = $change['ts'] + $delta;
			}
		}

		foreach ( $configs as $partial ) {
			$config = Schedule::sanitize( $partial );
			foreach ( $instants as $now ) {
				$next = Schedule::nextRun( $config, $tz, $now );
				$this->assertGreaterThan( $now, $next );
				$this->assertLessThanOrEqual( $limits[ $config['frequency'] ], $next - $now, $zone . ' ' . $config['frequency'] );

				// The wall time the run stands for: normally the one shown.
				$local  = ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( $tz );
				$intent = $next + $local->getOffset();
				if ( $local->format( 'H:i' ) !== $config['time'] ) {
					// Only a skipped wall time may show a different (later)
					// time; with the offset in force before the gap it is the
					// configured time, on the configured day (which may be the
					// day before the one shown, when the gap crosses midnight).
					$before = ( new \DateTimeImmutable( '@' . ( $next - 3600 ) ) )->setTimezone( $tz )->getOffset();
					$this->assertNotSame( $before, $local->getOffset(), 'unexpected wall time ' . $local->format( 'c' ) );
					$intent = $next + $before;
					$this->assertSame( $config['time'], gmdate( 'H:i', $intent ), 'moved from ' . $local->format( 'c' ) );
				}
				if ( 'weekly' === $config['frequency'] ) {
					$this->assertSame( $config['weekday'], (int) gmdate( 'w', $intent ) );
				}
				if ( 'monthly' === $config['frequency'] ) {
					$this->assertSame( -1 === $config['monthday'] ? (int) gmdate( 't', $intent ) : $config['monthday'], (int) gmdate( 'j', $intent ) );
				}
				// Stable: asking again before the result gives the result.
				foreach ( $this->momentsBetween( $tz, $now, $next ) as $moment ) {
					$this->assertSame( $next, Schedule::nextRun( $config, $tz, $moment ), $zone . ' ' . json_encode( $partial ) . ' asked at ' . ( new \DateTimeImmutable( '@' . $moment ) )->setTimezone( $tz )->format( 'c' ) . ' after ' . ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'c' ) );
				}
				$this->assertGreaterThan( $next, Schedule::nextRun( $config, $tz, $next ) );
			}
		}
	}

	/**
	 * Moments in [$from, $to): the ends, the middle and every local
	 * midnight in between.
	 *
	 * @param \DateTimeZone $tz   Time zone.
	 * @param int           $from Start.
	 * @param int           $to   End (excluded).
	 * @return int[]
	 */
	private function momentsBetween( \DateTimeZone $tz, $from, $to ) {
		$moments = array( $from + 1, intdiv( $from + $to, 2 ), $to - 1 );
		$day     = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( $tz )->setTime( 0, 0 );
		for ( $k = 0; $k <= 32; $k++ ) {
			$midnight = $day->modify( '+' . $k . ' days' )->setTime( 0, 0 )->getTimestamp();
			if ( $midnight >= $to ) {
				break;
			}
			$moments[] = $midnight;
		}
		return array_values(
			array_filter(
				array_unique( $moments ),
				function ( $moment ) use ( $from, $to ) {
					return $moment > $from && $moment < $to;
				}
			)
		);
	}

	/**
	 * Zones for the property test.
	 *
	 * @return array
	 */
	public static function zones() {
		return array(
			'UTC'                                => array( 'UTC' ),
			'Europe/Zurich'                      => array( 'Europe/Zurich' ),
			'America/New_York'                   => array( 'America/New_York' ),
			'Asia/Kolkata'                       => array( 'Asia/Kolkata' ),
			'Australia/Sydney'                   => array( 'Australia/Sydney' ),
			'Lord Howe (30 minute switch)'       => array( 'Australia/Lord_Howe' ),
			'America/Nuuk (gap across midnight)' => array( 'America/Nuuk' ),
		);
	}

	public function testNextRunTakesSanitizedFieldsLeniently() {
		// String values straight from a stored file still work.
		$next = Schedule::nextRun( array( 'frequency' => 'weekly', 'weekday' => '0', 'time' => '3:00' ), new \DateTimeZone( 'UTC' ), $this->ts( '2026-09-27T04:00:00+00:00' ) );
		$this->assertSame( $this->ts( '2026-10-04T03:00:00+00:00' ), $next );
		// A damaged time falls back to the default instead of throwing.
		$next = Schedule::nextRun( array( 'frequency' => 'daily', 'time' => 'soon' ), new \DateTimeZone( 'UTC' ), $this->ts( '2026-09-28T10:00:00+00:00' ) );
		$this->assertSame( $this->ts( '2026-09-29T03:00:00+00:00' ), $next );
	}

	/* ------------------------------------------------------------------
	 * describe
	 * ------------------------------------------------------------------ */

	public function testDescribe() {
		$this->assertSame( 'Only on demand', Schedule::describe( Schedule::defaults() ) );
		$this->assertSame( 'Only on demand', Schedule::describe( array() ) );
		$this->assertSame( 'Daily at 03:00', Schedule::describe( array( 'frequency' => 'daily' ) ) );
		$this->assertSame( 'Daily at 07:05', Schedule::describe( array( 'frequency' => 'daily', 'time' => '7:05' ) ) );
		$this->assertSame( 'Weekly on Sunday at 03:00', Schedule::describe( array( 'frequency' => 'weekly', 'weekday' => 0 ) ) );
		$this->assertSame( 'Weekly on Wednesday at 22:15', Schedule::describe( array( 'frequency' => 'weekly', 'weekday' => 3, 'time' => '22:15' ) ) );
		$this->assertSame( 'Weekly on Saturday at 03:00', Schedule::describe( array( 'frequency' => 'weekly', 'weekday' => '6' ) ) );
		$this->assertSame( 'Monthly on day 1 at 03:00', Schedule::describe( array( 'frequency' => 'monthly' ) ) );
		$this->assertSame( 'Monthly on day 28 at 03:00', Schedule::describe( array( 'frequency' => 'monthly', 'monthday' => 28 ) ) );
		$this->assertSame( 'Monthly on the last day at 03:00', Schedule::describe( array( 'frequency' => 'monthly', 'monthday' => -1 ) ) );
		$this->assertSame( 'Monthly on the last day at 23:59', Schedule::describe( array( 'frequency' => 'monthly', 'monthday' => 'last', 'time' => '23:59' ) ) );
		$this->assertSame( 'Only on demand', Schedule::describe( array( 'frequency' => 'hourly' ) ) );
	}

	public function testWeekdayNames() {
		$names = Schedule::weekdayNames();
		$this->assertSame( array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ), $names );
		// Indexed like date( 'w' ).
		$this->assertSame( $names[ (int) gmdate( 'w', 0 ) ], gmdate( 'l', 0 ) );
	}
}
