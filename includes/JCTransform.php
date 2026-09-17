<?php

namespace MediaWiki\Extension\JsonConfig;

use stdClass;

class JCTransform {
	/**
	 * @param string $module Lua module name
	 * @param string $function function name
	 * @param array $args optional array to pass JSON-compatible arguments
	 */
	public function __construct(
		private readonly string $module,
		private readonly string $function,
		private readonly array $args = [],
	) {
	}

	public function getModule(): string {
		return $this->module;
	}

	public function getFunction(): string {
		return $this->function;
	}

	public function getArgs(): array {
		return $this->args;
	}

	/**
	 * Re-hydrate a JSON object describing a transform into a JCTransform.
	 */
	public static function newFromJson( stdClass $object ): self {
		return new self(
			strval( $object->module ?? '' ),
			strval( $object->function ?? '' ),
			(array)( $object->args ?? [] )
		);
	}

	/**
	 * Create a JSON-compatible object describing the transform.
	 */
	public function toJson(): stdClass {
		return (object)[
			'module' => $this->module,
			'function' => $this->function,
			'args' => $this->args
		];
	}
}
