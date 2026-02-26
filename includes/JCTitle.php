<?php
namespace MediaWiki\Extension\JsonConfig;

use InvalidArgumentException;
use MediaWiki\Title\TitleValue;
use stdClass;

/**
 * A value object class that contains namespace ID, title, and
 * the corresponding jsonconfig configuration
 */
final class JCTitle extends TitleValue {

	/**
	 * JCTitle constructor.
	 * @param int $namespace Possibly belonging to a foreign wiki
	 * @param string $dbkey
	 * @param stdClass $config JsonConfig configuration object
	 */
	public function __construct(
		$namespace,
		$dbkey,
		private readonly stdClass $config,
	) {
		if ( $namespace !== $config->namespace ) {
			throw new InvalidArgumentException( 'Namespace does not match config' );
		}
		parent::__construct( $namespace, $dbkey );
	}

	/**
	 * @return stdClass
	 */
	public function getConfig() {
		return $this->config;
	}
}

/** @deprecated Temporary backwards-compatible class alias */
class_alias( JCTitle::class, 'JsonConfig\\JCTitle' );
