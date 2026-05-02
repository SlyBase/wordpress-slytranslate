<?php

declare(strict_types=1);

// WordPress constant required by the ABSPATH guard in plugin files.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

// Load Composer autoloader (PHPUnit, etc.).
require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// WP_Error stub class (defined here, not in a separate file).
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string, string[]> */
		private array $errors = [];

		/** @var array<string, mixed> */
		private array $error_data = [];

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( string $code, string $message, mixed $data = '' ): void {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/** @return string[] */
		public function get_error_messages( string $code = '' ): array {
			if ( '' !== $code ) {
				return $this->errors[ $code ] ?? [];
			}
			$messages = [];
			foreach ( $this->errors as $msgs ) {
				$messages = array_merge( $messages, $msgs );
			}
			return $messages;
		}

		/** @return string[] */
		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		public function get_error_code(): string {
			$codes = $this->get_error_codes();
			return $codes[0] ?? '';
		}

		public function get_error_message( string $code = '' ): string {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			$messages = $this->errors[ $code ] ?? [];
			return $messages[0] ?? '';
		}

		public function get_error_data( string $code = '' ): mixed {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return $this->error_data[ $code ] ?? null;
		}

		public function get_all_error_data( string $code = '' ): array {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			if ( ! array_key_exists( $code, $this->error_data ) ) {
				return array();
			}

			return array( $this->error_data[ $code ] );
		}
	}
}

// ---------------------------------------------------------------------------
// WP_Post stub class
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_status = 'publish';
		public string $post_type = 'post';
		public string $post_title = '';
		public string $post_content = '';
		public string $post_excerpt = '';

		/** @param array<string, mixed> $data */
		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

// ---------------------------------------------------------------------------
// Minimal REST API stub classes
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		public const CREATABLE = 'POST';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params = [];

		public function __construct( string $method = 'GET', string $route = '' ) {}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

// ---------------------------------------------------------------------------
// WordPress function stubs with lightweight per-test overrides.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/stubs/wp-stubs.php';

// ---------------------------------------------------------------------------
// Plugin source files
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../inc/TranslationPluginAdapter.php';
require_once __DIR__ . '/../inc/SeoPluginDetector.php';
require_once __DIR__ . '/../inc/EditorBootstrap.php';
require_once __DIR__ . '/../slytranslate.php';
