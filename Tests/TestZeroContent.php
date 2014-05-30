<?php
if ( !defined( 'MEDIAWIKI' ) ) {
	die( -1 );
}

use JsonConfig\JCKeyValueContent;

define( 'NS_ZERO', 480 );
define( 'NS_ZERO_TALK', 481 );

$wgJsonConfigModels['Test.JsonZeroConfig'] = 'TestZeroContent';

//$wgJsonConfigs['Test.JsonZeroConfig'] = array(
//	// model is the same as key
//	'name' => 'ZeroSingle',
//	'islocal' => true,
//);
//$wgJsonConfigs['Test.Zero.Subpages'] = array(
//	'model' => 'Test.JsonZeroConfig',
//	'name' => 'Zero',
//	'issubspace' => true,
//	'islocal' => true,
//);
//$wgJsonConfigs['Test.Zero.Ns'] = array(
//	'model' => 'Test.JsonZeroConfig',
//	'namespace' => 600,
//	'nsname' => 'Z',
//	'islocal' => true,
//);
$wgJsonConfigs['Test.Zero.Ns'] = array(
	'model' => 'Test.JsonZeroConfig',
	'namespace' => NS_ZERO,
	'nsname' => 'Zero',
	'islocal' => false,
	'url' => 'https://zero.wikimedia.org/w/api.php',
	'username' => $wmgZeroRatedMobileAccessApiUserName,
	'password' => $wmgZeroRatedMobileAccessApiPassword,
);

class TestZeroContent extends JCKeyValueContent {
	public function __construct( $text, $modelId, $isSaving ) {
		if ( $text === null ) {
			$text = <<<END
{
    "name": {
        "en": "Test"
    },
    "showLangs": [
        "en"
    ],
    "whitelistedLangs": []
}
END;
		}
		parent::__construct( $text, $modelId, $isSaving );
	}

	public function validate( $data ) {
		$this->initValidation( $data );

		// Optional comment
		$this->check( 'comment', '', self::getStrValidator() );

		//'enabled' => true,          // Config is enabled
		$this->check( 'enabled', true, self::getBoolValidator() );

		// List of additional partner admins for this entry
		if ( $this->isSaving() ) {
			$this->check( 'admins', array(),
				function ( $fld, $v ) {
					if ( is_string( $v ) ) {
						$v = array( $v );
					} elseif ( !JCKeyValueContent::isArray( $v, false ) ||
						!JCKeyValueContent::isArrayOfStrings( $v )
					) {
						return wfMessage( 'zeroconfig-admins', $fld );
					}
					$v2 = array();
					foreach ( $v as $name ) {
						$usr = \User::newFromName( $name );
						if ( $usr === false || $usr->getId() === 0 ) {
							return wfMessage( 'zeroconfig-admins', $fld );
						}
						$v2[] = $usr->getName();
					}

					return TestZeroContent::normalizeAdmins( $v2 );
				} );
		}

		// Which sites are whitelisted. Default - both m & zero wiki
		$this->check( 'sites',
			array(
				'm.wikipedia',
				'zero.wikipedia',
			),
			function ( $fld, $v ) {
				if ( is_string( $v ) ) {
					$oldValues = array(
						'zero' => 'zero.wikipedia',
						'm' => 'm.wikipedia',
						'both' => array( 'm.wikipedia', 'zero.wikipedia' ) );
					$v = strtolower( $v );
					if ( array_key_exists( $v, $oldValues ) ) {
						$v = $oldValues[$v];
					}
					if ( is_string( $v ) ) {
						$v = array( $v );
					}
				}
				$validValues = array(
					'm.wikipedia',
					'zero.wikipedia',
				);
				if ( is_array( $v ) ) {
					$v = array_map( 'strtolower', $v );
					// FIXME: remove this after refreshing all meta configs
					foreach ( $v as &$item ) {
						if ( $item === 'zero.wiki' ) {
							$item = 'zero.wikipedia';
						} elseif ( $item === 'm.wiki' ) {
							$item = 'm.wikipedia';
						}
					}
					if ( count( array_intersect( $v, $validValues ) ) !== count( $v ) ) {
						$v = false;
					} else {
						$v = array_unique( $v );
						sort( $v );
					}
				}
				if ( JCKeyValueContent::isArray( $v, false )
					&& count( $v ) > 0
					&& JCKeyValueContent::isArrayOfStrings( $v )
				) {
					return $v;
				}

				return wfMessage( 'zeroconfig-sites', $fld, "'" . implode( "', '", $validValues ) . "'" )
					->numParams( count( $validValues ) );
			} );

		// If carrier wants to suppress zero messaging in apps
		$this->check( 'disableApps', false, self::getBoolValidator() );

		//'name' => null,             // Map of localized partner names
		$this->check( 'name', null,
			function ( $fld, $v ) {
				return JCKeyValueContent::isArray( $v, true )
						&& TestZeroContent::isArrayOfLangs( array_keys( $v ) )
						&& JCKeyValueContent::isArrayOfStrings( $v )
					? TestZeroContent::sortLangArray( $v ) : wfMessage( 'zeroconfig-name', $fld );
			} );

		//'banner' => null,           // Map of localized banner texts with {{PARTNER}} placeholder
		$this->check( 'banner', array(),
			function ( $fld, $v ) {
				return JCKeyValueContent::isArray( $v, true )
						&& TestZeroContent::isArrayOfLangs( array_keys( $v ) )
						&& JCKeyValueContent::isArrayOfStrings( $v )
					? TestZeroContent::sortLangArray( $v ) : wfMessage( 'zeroconfig-banner', $fld );
			} );

		// Partner URL, do not link by default
		$this->check( 'bannerUrl', '',
			function ( $fld, $v ) {
				return
					( $v === '' || false !== filter_var( $v, FILTER_VALIDATE_URL ) )
						? $v : wfMessage( 'zeroconfig-banner_url', $fld );
			} );

		//'showLangs' => null,        // List of language codes to show on Zero page
		$this->check( 'showLangs', null,
			function ( $fld, $v ) {
				if ( is_string( $v ) ) {
					$v = array( $v );
				}
				if ( JCKeyValueContent::isArray( $v, false )
					&& TestZeroContent::isArrayOfLangs( $v )
					&& count( $v ) > 0
				) {
					// Remove duplicates while preserving original order
					$v = array_unique( $v );
					ksort( $v );

					return $v;
				}

				return wfMessage( 'zeroconfig-show_langs', $fld );
			} );

		// List of language codes to show banner on, or empty list to allow on all languages
		$this->check( 'whitelistedLangs', null,
			function ( $fld, $v, $self ) {
				/** @var $self TestZeroContent */
				if ( is_string( $v ) ) {
					$v = array( $v );
				}
				$data = $self->getDataWithDefaults();
				$showLangs = array_key_exists( 'showLangs', $data ) ? $data['showLangs'] : array();
				if ( JCKeyValueContent::isArray( $v, false )
					&& TestZeroContent::isArrayOfLangs( $v )
					&& ( count( $v ) === 0 || count( array_diff( $showLangs, $v ) ) === 0 )
				) {
					if ( count( $v ) === 0 ) {
						// Empty list is the same as whitelist all languages
						return $v;
					}
					// Make $v in the same order as $showLangs, followed by alphabetical leftovers
					$v = array_unique( $v );
					$leftovers = array_diff( $v, $showLangs );
					sort( $leftovers );

					return array_merge( $showLangs, $leftovers );
				}

				return wfMessage( 'zeroconfig-whitelisted_langs', $fld );
			} );

		// Orange Congo wanted to be able to override the 'kg' language name to 'Kikongo'
		$this->check( 'langNameOverrides', array(),
			function ( $fld, $v ) {
				return JCKeyValueContent::isArray( $v, true )
						&& TestZeroContent::isArrayOfLangs( array_keys( $v ) )
						&& JCKeyValueContent::isArrayOfStrings( $v )
					? TestZeroContent::sortLangArray( $v ) : wfMessage( 'zeroconfig-lang_name_overrides', $fld );
			} );

		// List of proxies supported by the carrier, defaults to none (empty list)
		$this->check( 'proxies', array(),
			function ( $fld, $v, $self ) {
				if ( JCKeyValueContent::isArray( $v, false ) ) {
					/** @var JCKeyValueContent $self */
					if ( $self->isSaving() ) {
						// Remove duplicates while preserving original order
						$v = array_unique( $v );
						ksort( $v );
					}

					return $v;
				}

				return wfMessage( 'zeroconfig-proxies', $fld );
			} );

		// Background banner color
		$this->check( 'background', '#E31230', self::getStrValidator() );

		// Foreground banner color
		$this->check( 'foreground', '#551011', self::getStrValidator() );

		// Banner font size override
		$this->check( 'fontSize', '', self::getStrValidator() );

		// Show "non-zero navigation" warning when clicking the banner
		$this->check( 'bannerWarning', true, self::getBoolValidator() );

		// Zero rate images.
		// @BUG? does this have the same meaning as legacy "IMAGES_ON"?
		$this->check( 'showImages', true, self::getBoolValidator() );

		// Show the special zero page
		// default = ( count( 'showLangs' ) > 1 )
		$this->check( 'showZeroPage', true, self::getBoolValidator() );

		// If carrier supports zero-rating HTTPS traffic
		$this->check( 'enableHttps', false, self::getBoolValidator() );

		// List of IP CIDR blocks for this provider
		if ( $this->isSaving() ) {
			$this->check( 'ips', array(),
				function ( $fld, $v ) {
					if ( is_string( $v ) ) {
						$v = array( $v );
					} elseif ( !JCKeyValueContent::isArray( $v, false ) ||
						!JCKeyValueContent::isArrayOfStrings( $v )
					) {
						return wfMessage( 'zeroconfig-ips', $fld );
					}

					// At some point we might remove FILTER_FLAG_NO_PRIV_RANGE
					// to allow local network testing
					$ipFlags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
					$optIPv4 = array( 'options' => array( 'min_range' => 1, 'max_range' => 32 ) );
					$optIPv6 = array( 'options' => array( 'min_range' => 1, 'max_range' => 128 ) );

					foreach ( $v as $cidr ) {
						$parts = explode( '/', $cidr, 3 );
						if ( count( $parts ) > 2
							|| false === filter_var( $parts[0], FILTER_VALIDATE_IP, $ipFlags )
						) {
							return wfMessage( 'zeroconfig-ips', $fld );
						}
						if ( count( $parts ) === 2 ) {
							// CIDR block, second portion must be an integer within range
							// If the first part has ':', treat it as IPv6
							// Make sure there are no spaces in the block size
							$blockSize = filter_var(
								$parts[1], FILTER_VALIDATE_INT,
								strpos( $parts[0], ':' ) !== false ? $optIPv6 : $optIPv4 );
							if ( false === $blockSize || $parts[1] !== strval( $blockSize ) ) {
								return wfMessage( 'zeroconfig-ips', $fld );
							}
						}
					}
					// Sort in natural order, but force sequential keys to prevent json to treat it as dictionary.
					natsort( $v );

					return array_values( $v );
				} );
		}

		// In case 'showZeroPage' is not set, the default depends on how many languages are shown.
		if ( isset( $this->defaultFields['showZeroPage'] ) &&
			!isset( $this->defaultFields['showLangs'] ) &&
			isset( $this->dataWithDefaults['showLangs'] )
		) {
			$this->dataWithDefaults['showZeroPage'] = count( $this->dataWithDefaults['showLangs'] ) > 1;
		}

		return $this->finishValidation();
	}

	/**
	 * Returns true if each of the array's values is a valid language code
	 */
	static function isArrayOfLangs( $arr ) {
		$filter = function ( $v ) {
			return \Language::isValidCode( $v );
		};

		return count( $arr ) === count( array_filter( $arr, $filter ) );
	}

	/**
	 * Sort array so that the values are sorted alphabetically except 'en' which will go as first
	 */
	static function sortLangArray( $arr ) {
		uksort( $arr,
			function ( $a, $b ) {
				if ( $a === $b ) {
					return 0;
				} elseif ( $a === 'en' ) {
					return -1;
				} elseif ( $b === 'en' ) {
					return 1;
				} else {
					return strcasecmp( $a, $b );
				}
			} );

		return $arr;
	}

	/**
	 * Normalize the list of users so that they are sorted and unique
	 */
	static function normalizeAdmins( $admins ) {
		$admins = array_unique( $admins );
		sort( $admins );

		return array_values( $admins );
	}
}


$wgExtensionFunctions[] = function() {

	$content = \JsonConfig\JCSingleton::getContent( new TitleValue( NS_ZERO, '250-99' ) );

//	var_dump( $content );
};