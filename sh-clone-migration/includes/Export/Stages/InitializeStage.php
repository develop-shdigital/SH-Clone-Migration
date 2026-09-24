<?php
/**
 * Export: environment validation.
 *
 * @package SHCM
 */

namespace SHCM\Export\Stages;

use SHCM\Core\Environment;
use SHCM\Core\Settings;
use SHCM\Filesystem\Paths;
use SHCM\Filesystem\Storage;
use SHCM\Jobs\AbstractStage;
use SHCM\Jobs\Budget;
use SHCM\Jobs\Job;
use SHCM\Logging\Logger;
use SHCM\Support\Bytes;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the environment and decides where the archive will be written.
 */
class InitializeStage extends AbstractStage {

	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	protected $environment;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings    Settings.
	 * @param Storage     $storage     Storage.
	 * @param Logger      $logger      Logger.
	 * @param Environment $environment Environment.
	 */
	public function __construct( Settings $settings, Storage $storage, Logger $logger, Environment $environment ) {
		parent::__construct( $settings, $storage, $logger );
		$this->environment = $environment;
	}

	/**
	 * Stage key.
	 *
	 * @return string
	 */
	public function key() {
		return 'initialize';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Validating environment', 'sh-clone-migration' );
	}

	/**
	 * Weight.
	 *
	 * @return int
	 */
	public function weight() {
		return 2;
	}

	/**
	 * Run.
	 *
	 * @param Job    $job    Job.
	 * @param Budget $budget Budget.
	 * @return \SHCM\Core\Result
	 */
	public function run( Job $job, Budget $budget ) {
		unset( $budget );

		$this->logger->info( 'Validating the environment.' );

		if ( ! $this->storage->prepare() ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: directory */
					__( 'The storage directory %s is not writable. Fix its permissions and start the migration again.', 'sh-clone-migration' ),
					$this->storage->base()
				)
			);
		}

		$free = $this->storage->freeSpace();
		if ( $free >= 0 && $free < 100 * 1024 * 1024 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: free space */
					__( 'Only %s of disk space is available. Free some space before exporting.', 'sh-clone-migration' ),
					Bytes::format( $free )
				)
			);
		}

		$estimate = $this->environment->estimateSiteSize();
		if ( $free >= 0 && $estimate > 0 && $free < $estimate ) {
			$job->addWarning(
				sprintf(
					/* translators: 1: free space, 2: estimated size */
					__( 'Free disk space (%1$s) is below the rough size estimate for this site (%2$s). The export continues, and will stop cleanly if the disk fills up.', 'sh-clone-migration' ),
					Bytes::format( $free ),
					Bytes::format( $estimate )
				)
			);
		}

		if ( ! $job->param( 'archive_path' ) ) {
			$job->setParam( 'archive_path', $this->archivePath( $job ) );
		}
		$job->setParam( 'block_size', $this->settings->getInt( 'block_size', 1048576 ) );
		$job->setParam( 'compression', $this->environment->compressionEngine( $this->settings->get( 'compression', 'auto' ) ) );
		$job->setParam( 'free_space_at_start', $free );

		$this->logger->info(
			sprintf(
				'Environment OK. PHP %1$s, memory limit %2$s, free disk %3$s, archive: %4$s',
				PHP_VERSION,
				Bytes::format( $this->environment->can( 'memory_limit' ) ),
				$free < 0 ? 'unknown' : Bytes::format( $free ),
				basename( $job->param( 'archive_path' ) )
			)
		);

		return $this->complete( __( 'Environment checked', 'sh-clone-migration' ) );
	}

	/**
	 * Build the archive file path.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	protected function archivePath( Job $job ) {
		$name = $job->param( 'name' );
		if ( ! $name ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			$name = sanitize_file_name( (string) $host );
			if ( '' === $name ) {
				$name = 'wordpress';
			}
			$name .= '-' . gmdate( 'Ymd-His' );
		}
		$name = self::safeName( $name );
		if ( '' === $name ) {
			$name = 'wordpress-' . gmdate( 'Ymd-His' );
		}

		// The suffix is unguessable on purpose: on a web server that ignores
		// .htaccess the archive path is the only thing standing between an
		// anonymous visitor and a copy of the whole site.
		return Paths::trailingslash( $this->storage->archives() ) . $name . '-' . bin2hex( random_bytes( 8 ) ) . '.wpress';
	}

	/**
	 * Reduce an archive name to the characters the download, delete and
	 * verify handlers accept ([A-Za-z0-9._-]). sanitize_file_name() keeps
	 * letters such as "@", "^" or Cyrillic, and an archive named with them
	 * could be created but never downloaded.
	 *
	 * @param string $name Requested name.
	 * @return string
	 */
	public static function safeName( $name ) {
		$name = function_exists( 'remove_accents' ) ? remove_accents( (string) $name ) : (string) $name;
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
		$name = preg_replace( '/-{2,}/', '-', (string) $name );
		return trim( (string) $name, '.-_' );
	}

	/**
	 * Remove a half written archive when the job dies here.
	 *
	 * @param Job             $job   Job.
	 * @param \Throwable|null $error Error.
	 * @return void
	 */
	public function cleanup( Job $job, $error = null ) {
		unset( $job, $error );
	}
}
