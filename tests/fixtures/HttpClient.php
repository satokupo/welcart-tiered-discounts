<?php
/**
 * Small HTTP client for local integration tests.
 *
 * @package Welcart_Tiered_Discounts
 */

if ( ! class_exists( 'WtdTestHttp' ) ) {
	/**
	 * Cookie-preserving client restricted to the C integration site.
	 */
	final class WtdTestHttp {
		/** @var string */
		private $base_url;

		/** @var string */
		private $cookie_jar;

		/** @var int */
		private $local_port;

		/**
		 * @param string|null $base_url Site URL. Defaults to the loaded WordPress home option.
		 */
		public function __construct( $base_url = null ) {
			$this->local_port = $this->test_port();
			if ( null === $base_url ) {
				if ( ! function_exists( 'get_option' ) ) {
					throw new RuntimeException( 'WordPress must be loaded before WtdTestHttp.' );
				}
				$base_url = get_option( 'home', '' );
			}
			$this->base_url = $this->local_url( (string) $base_url );
			$this->cookie_jar = tempnam( sys_get_temp_dir(), 'wtd-http-' );
			if ( false === $this->cookie_jar ) {
				throw new RuntimeException( 'Unable to create the HTTP cookie jar.' );
			}
		}

		/**
		 * Send a GET request or form-encoded POST request.
		 *
		 * @param string     $path Relative local path, optionally with a query string.
		 * @param array|null $fields Null sends GET; an array sends POST.
		 * @param array    $headers Optional request headers for local test controls.
		 * @return array{status:int,body:string,url:string}
		 */
		public function request( $path, $fields = null, $headers = array() ) {
			$url          = $this->local_url( $path, $this->base_url );
			$request_data = $fields;
			$redirects    = 0;

			while ( $redirects <= 5 ) {
				$location = null;
				$ch       = curl_init( $url );
				if ( false === $ch ) {
					throw new RuntimeException( 'Unable to initialize cURL.' );
				}
				$options = array(
					CURLOPT_CONNECTTIMEOUT => 5,
					CURLOPT_COOKIEFILE     => $this->cookie_jar,
					CURLOPT_COOKIEJAR      => $this->cookie_jar,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_HEADERFUNCTION => function ( $handle, $header ) use ( &$location ) {
						if ( preg_match( '/^Location:\s*(.+?)\s*$/i', $header, $matches ) ) {
							$location = $matches[1];
						}
						return strlen( $header );
					},
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 30,
					CURLOPT_USERAGENT      => 'WtdTestHttp/1.0',
				);
				if ( null !== $request_data ) {
					$options[ CURLOPT_POST ]       = true;
					$options[ CURLOPT_POSTFIELDS ] = http_build_query( $request_data, '', '&' );
				}
				if ( defined( 'CURLOPT_CONNECT_TO' ) ) {
					$options[ CURLOPT_CONNECT_TO ] = array( '127.0.0.1:' . $this->local_port . ':wordpress:80' );
				}
				curl_setopt_array( $ch, $options );
				$raw_body = curl_exec( $ch );
				if ( false === $raw_body ) {
					$error = curl_error( $ch );
					if ( is_resource( $ch ) ) {
						curl_close( $ch );
					}
					throw new RuntimeException( 'HTTP request failed: ' . $error );
				}
				$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
				if ( is_resource( $ch ) ) {
					curl_close( $ch );
				}

				if ( 300 <= $status && 400 > $status && null !== $location ) {
					$url = $this->local_url( $location, $url );
					if ( 303 === $status || ( ( 301 === $status || 302 === $status ) && null !== $request_data ) ) {
						$request_data = null;
					}
					$redirects++;
					continue;
				}

				return array(
					'status' => $status,
					'body'   => (string) $raw_body,
					'url'    => $url,
				);
			}

			throw new RuntimeException( 'Too many local HTTP redirects.' );
		}

		/**
		 * Log in to WordPress and return the final response.
		 *
		 * @param string $username WordPress login name.
		 * @param string $password WordPress password.
		 * @return array{status:int,body:string,url:string}
		 */
		public function login( $username, $password ) {
			// WordPress checks the test cookie issued by the login form's GET request.
			$this->request( '/wp-login.php' );
			return $this->request(
				'/wp-login.php',
				array(
					'log'         => $username,
					'pwd'         => $password,
					'wp-submit'   => 'Log In',
					'redirect_to' => $this->base_url . '/wp-admin/',
					'testcookie'  => '1',
				)
			);
		}

		/**
		 * Return the validated local integration port used by this client.
		 *
		 * @return int Local HTTP port.
		 */
		public function port() {
			return $this->local_port;
		}

		/**
		 * Extract named controls from a form.
		 *
		 * Control names are returned verbatim, including bracket syntax such as
		 * `quant[0][6][wtd-standard]`, so callers can submit them through the
		 * same form contract as a browser.
		 *
		 * @param string      $html Form HTML.
		 * @param string|null $form_id Optional form ID.
		 * @return array<string,mixed>
		 */
		public static function formFields( $html, $form_id = null ) {
			$previous = libxml_use_internal_errors( true );
			$document = new DOMDocument();
			$document->loadHTML( '<?xml encoding="UTF-8">' . $html );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			$forms = $document->getElementsByTagName( 'form' );
			$form  = null;
			foreach ( $forms as $candidate ) {
				if ( null === $form_id || $form_id === $candidate->getAttribute( 'id' ) ) {
					$form = $candidate;
					break;
				}
			}
			if ( ! $form ) {
				return array();
			}

			$fields = array();
			$inputs = $form->getElementsByTagName( 'input' );
			foreach ( $inputs as $input ) {
				$name = $input->getAttribute( 'name' );
				$type = strtolower( $input->getAttribute( 'type' ) );
				if ( '' === $name || $input->hasAttribute( 'disabled' ) || in_array( $type, array( 'button', 'image', 'reset', 'submit' ), true ) ) {
					continue;
				}
				if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $input->hasAttribute( 'checked' ) ) {
					continue;
				}
				WtdTestHttp::addField( $fields, $name, $input->getAttribute( 'value' ) );
			}

			$textareas = $form->getElementsByTagName( 'textarea' );
			foreach ( $textareas as $textarea ) {
				$name = $textarea->getAttribute( 'name' );
				if ( '' !== $name && ! $textarea->hasAttribute( 'disabled' ) ) {
					WtdTestHttp::addField( $fields, $name, $textarea->textContent );
				}
			}

			$selects = $form->getElementsByTagName( 'select' );
			foreach ( $selects as $select ) {
				$name = $select->getAttribute( 'name' );
				if ( '' === $name || $select->hasAttribute( 'disabled' ) ) {
					continue;
				}
				$values   = array();
				$options  = $select->getElementsByTagName( 'option' );
				$multiple = $select->hasAttribute( 'multiple' );
				foreach ( $options as $option ) {
					if ( $option->hasAttribute( 'selected' ) ) {
						$values[] = $option->getAttribute( 'value' );
					}
				}
				if ( ! $multiple && empty( $values ) && $options->length > 0 ) {
					$values[] = $options->item( 0 )->getAttribute( 'value' );
				}
				if ( $multiple ) {
					$fields[ $name ] = $values;
				} elseif ( isset( $values[0] ) ) {
					WtdTestHttp::addField( $fields, $name, $values[0] );
				}
			}

			return $fields;
		}

		/**
		 * Resolve and validate a URL on the fixed local integration host.
		 *
		 * @param string      $value Relative path or URL.
		 * @param string|null $base Base URL used for relative locations.
		 * @return string
		 */
		private function local_url( $value, $base = null ) {
			$value = (string) $value;
			if ( '' === $value ) {
				$value = '/';
			}
			if ( 0 !== strpos( $value, 'http://' ) && 0 !== strpos( $value, 'https://' ) ) {
				$base_parts = parse_url( $base ? $base : $this->base_url );
				$path       = 0 === strpos( $value, '/' ) ? $value : '/' . $value;
				$value      = $base_parts['scheme'] . '://' . $base_parts['host'] . ( isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '' ) . $path;
			}
			$parts = parse_url( $value );
			if ( ! is_array( $parts ) || 'http' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) || '127.0.0.1' !== ( isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '' ) || $this->local_port !== (int) ( isset( $parts['port'] ) ? $parts['port'] : 80 ) ) {
				throw new InvalidArgumentException( 'HTTP requests are restricted to http://127.0.0.1:' . $this->local_port . '.' );
			}
			return $value;
		}

		/**
		 * Return the explicitly allowed local integration port.
		 *
		 * @return int Local HTTP port.
		 */
		private function test_port() {
			$port = getenv( 'WTD_TEST_PORT' );
			if ( false === $port || '' === $port ) {
				$port = '8080';
			}
			if ( ! is_string( $port ) || ! preg_match( '/^[1-9][0-9]{0,4}$/', $port ) || 65535 < (int) $port ) {
				throw new InvalidArgumentException( 'WTD_TEST_PORT must be a valid local HTTP port.' );
			}
			return (int) $port;
		}

		/**
		 * Append a form value while preserving repeated controls.
		 *
		 * @param array  $fields Fields being built.
		 * @param string $name Field name.
		 * @param mixed  $value Field value.
		 * @return void
		 */
		private static function addField( array &$fields, $name, $value ) {
			if ( ! array_key_exists( $name, $fields ) ) {
				$fields[ $name ] = $value;
			} elseif ( is_array( $fields[ $name ] ) ) {
				$fields[ $name ][] = $value;
			} else {
				$fields[ $name ] = array( $fields[ $name ], $value );
			}
		}
	}
}
