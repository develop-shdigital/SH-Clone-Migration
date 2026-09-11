<?php
/**
 * URL and path replacement engine.
 *
 * @package SHCM
 */

namespace SHCM\URL;

defined( 'ABSPATH' ) || defined( 'SHCM_ALLOW_STANDALONE' ) || exit;

/**
 * Applies a set of literal replacements to any value, staying correct for PHP
 * serialized payloads.
 *
 * Rules are literal strings rather than regular expressions so that a value is
 * either replaced exactly or left alone; the encoding variants a WordPress
 * database actually contains (escaped slashes inside JSON, percent encoded
 * URLs inside redirects, protocol relative references) are generated up front
 * as their own rules.
 */
class Replacer {

	/**
	 * Ordered replacements: from => to, longest source first.
	 *
	 * @var array<string,string>
	 */
	protected $rules = array();

	/**
	 * Cheap substrings used to skip values that cannot match.
	 *
	 * @var string[]
	 */
	protected $probes = array();

	/**
	 * Hosts to report on when they survive the replacement.
	 *
	 * @var string[]
	 */
	protected $report_hosts = array();

	/**
	 * Statistics.
	 *
	 * @var array
	 */
	protected $stats = array(
		'values_changed'      => 0,
		'strings_replaced'    => 0,
		'serialized_repaired' => 0,
		'serialized_failed'   => 0,
	);

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $rules Replacement rules.
	 */
	public function __construct( array $rules = array() ) {
		foreach ( $rules as $from => $to ) {
			$this->addRule( $from, $to );
		}
	}

	/**
	 * Add a replacement rule.
	 *
	 * @param string $from Source string.
	 * @param string $to   Replacement.
	 * @return self
	 */
	public function addRule( $from, $to ) {
		$from = (string) $from;
		if ( '' === $from || $from === $to ) {
			return $this;
		}
		$this->rules[ $from ] = (string) $to;
		uksort(
			$this->rules,
			static function ( $a, $b ) {
				$diff = strlen( $b ) - strlen( $a );
				return 0 !== $diff ? $diff : strcmp( $a, $b );
			}
		);
		return $this;
	}

	/**
	 * Register a cheap pre-check substring.
	 *
	 * @param string $probe Probe.
	 * @return self
	 */
	public function addProbe( $probe ) {
		$probe = (string) $probe;
		if ( '' !== $probe && ! in_array( $probe, $this->probes, true ) ) {
			$this->probes[] = $probe;
		}
		return $this;
	}

	/**
	 * Register a host that should be reported when it survives.
	 *
	 * @param string $host Host name.
	 * @return self
	 */
	public function addReportHost( $host ) {
		$host = strtolower( (string) $host );
		if ( '' !== $host && ! in_array( $host, $this->report_hosts, true ) ) {
			$this->report_hosts[] = $host;
		}
		return $this;
	}

	/**
	 * Configured rules.
	 *
	 * @return array<string,string>
	 */
	public function rules() {
		return $this->rules;
	}

	/**
	 * Hosts reported on.
	 *
	 * @return string[]
	 */
	public function reportHosts() {
		return $this->report_hosts;
	}

	/**
	 * Whether any rule is configured.
	 *
	 * @return bool
	 */
	public function isEmpty() {
		return empty( $this->rules );
	}

	/**
	 * Statistics collected so far.
	 *
	 * @return array
	 */
	public function stats() {
		return $this->stats;
	}

	/**
	 * Reset the statistics.
	 *
	 * @return void
	 */
	public function resetStats() {
		$this->stats = array(
			'values_changed'      => 0,
			'strings_replaced'    => 0,
			'serialized_repaired' => 0,
			'serialized_failed'   => 0,
		);
	}

	/**
	 * Fast test for whether a value can possibly contain something to replace.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public function mightMatch( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		foreach ( $this->probes as $probe ) {
			if ( false !== stripos( $value, $probe ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace inside a plain string.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function replaceString( $value ) {
		if ( '' === $value ) {
			return $value;
		}
		foreach ( $this->rules as $from => $to ) {
			if ( false !== strpos( $value, $from ) ) {
				$value = str_replace( $from, $to, $value );
			}
		}
		return $value;
	}

	/**
	 * Replace inside any database value, keeping serialization valid.
	 *
	 * @param mixed $value Value.
	 * @return array{value:mixed,changed:bool,serialized:bool,failed:bool,strings:int}
	 */
	public function apply( $value ) {
		$result = array(
			'value'      => $value,
			'changed'    => false,
			'serialized' => false,
			'failed'     => false,
			'strings'    => 0,
		);

		if ( ! is_string( $value ) || '' === $value ) {
			return $result;
		}
		if ( ! $this->mightMatch( $value ) ) {
			return $result;
		}

		if ( $this->looksSerialized( $value ) ) {
			$rewriter  = new SerializedRewriter();
			$rewritten = $rewriter->rewrite(
				$value,
				function ( $string ) {
					return $this->replaceString( $string );
				}
			);

			if ( $rewritten['ok'] ) {
				$result['serialized'] = true;
				if ( $rewritten['changed'] ) {
					$result['value']   = $rewritten['value'];
					$result['changed'] = true;
					$result['strings'] = $rewritten['strings'];
					++$this->stats['values_changed'];
					++$this->stats['serialized_repaired'];
					$this->stats['strings_replaced'] += $rewritten['strings'];
				}
				return $result;
			}

			if ( $this->isSerialized( $value ) ) {
				// Definitely a serialized payload, but not one we can parse.
				// Refuse to touch it: a broken serialized value is far worse
				// than an unreplaced URL, and the caller reports it.
				$result['serialized'] = true;
				$result['failed']     = true;
				++$this->stats['serialized_failed'];
				return $result;
			}
			// Not actually serialized (a Windows path such as C:\... or a
			// string that merely starts like a token): treat it as plain text.
		}

		$replaced = $this->replaceString( $value );
		if ( $replaced !== $value ) {
			$result['value']   = $replaced;
			$result['changed'] = true;
			$result['strings'] = 1;
			++$this->stats['values_changed'];
			++$this->stats['strings_replaced'];
		}

		return $result;
	}

	/**
	 * Count surviving references to the reported hosts.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	public function countRemaining( $value ) {
		if ( ! is_string( $value ) || '' === $value || empty( $this->report_hosts ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $this->report_hosts as $host ) {
			$count += substr_count( strtolower( $value ), $host );
		}
		return $count;
	}

	/**
	 * Whether a string could be a serialized payload of any supported type.
	 *
	 * Deliberately wider than is_serialized(): custom Serializable payloads
	 * (C:) are not recognised by WordPress but must still be parsed rather
	 * than string-replaced, or their byte counts go stale.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	protected function looksSerialized( $value ) {
		if ( strlen( $value ) < 4 ) {
			return false;
		}
		if ( ':' !== $value[1] ) {
			return 'N;' === substr( $value, 0, 2 );
		}
		return false !== strpos( 'aOsbidCE', $value[0] );
	}

	/**
	 * Whether a string is a PHP serialized payload.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	protected function isSerialized( $value ) {
		if ( 'C' === $value[0] && preg_match( '/^C:\\d+:"/', $value ) ) {
			return true;
		}
		if ( function_exists( 'is_serialized' ) ) {
			return is_serialized( $value, true );
		}
		return (bool) preg_match( '/^[aOsbid]:/', $value );
	}
}
