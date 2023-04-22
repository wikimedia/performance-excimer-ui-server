<?php

namespace Wikimedia\ExcimerUI\Server;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PDO;
use Psr\Log\LoggerInterface;
use Throwable;

class Server {
	private const DEFAULT_CONFIG = [
		'speedscopePath' => null,
		'url' => null,
		'asyncIngest' => true,
		'retentionDays' => 0,
		'logFormat' => "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
		'logFile' => '',
		'jsonLogFile' => '',
		'logToStderr' => false,
		'jsonLogToStderr' => false,
		'logToSyslog' => false,
		'hashKey' => null,
		'profileIdLength' => 16,
	];

	/** @var array */
	private $config;

	/** @var LoggerInterface */
	private $logger;

	/** @var ServerRequest */
	private $request;

	/**
	 * The main entry point. Call this from the webserver.
	 *
	 * @param string|null $configPath The location of the JSON config file
	 */
	public static function main( $configPath ) {
		( new self )->execute( $configPath );
	}

	/**
	 * Non-static entry point
	 *
	 * @param string|null $configPath
	 */
	private function execute( $configPath ) {
		try {
			$this->guardedExecute( $configPath );
		} catch ( Throwable $e ) {
			$this->handleException( $e );
		}
	}

	/**
	 * Entry point that may throw exceptions
	 *
	 * @param string|null $configPath
	 */
	private function guardedExecute( $configPath ) {
		$this->setupConfig( $configPath );
		$this->setupLogger();
		$this->request = ServerRequest::fromGlobals();

		$urlPath = $this->request->getUri()->getPath();
		$base = ( new Uri( $this->getConfig( 'url' ) ) )->getPath();
		if ( $base[-1] !== '/' ) {
			$base .= '/';
		}
		if ( substr_compare( $urlPath, $base, 0, strlen( $base ) ) !== 0 ) {
			throw new ServerError( "Request URL does not match configured base path", 404 );
		}
		$baseLength = strlen( $base );
		$pathInfo = substr( $urlPath, $baseLength );
		$components = explode( '/', $pathInfo );
		$action = array_shift( $components );

		switch ( $action ) {
			case 'healthz':
				$this->showHealth();
				break;
			case 'ingest':
				$this->ingest( $components );
				break;
			case 'fetch':
				$this->fetch( $components );
				break;
			case 'speedscope':
				$this->showSpeedscope( $components );
				break;
			case 'profile':
				$this->showProfile( $components );
				break;
			default:
				throw new ServerError( "Invalid action", 404 );
		}
	}

	/**
	 * Read the config file into $this->config
	 *
	 * @param string $configPath
	 */
	private function setupConfig( $configPath ) {
		if ( $configPath === null ) {
			$configPath = getenv( 'EXCIMER_CONFIG_PATH' ) ?: '';
			if ( $configPath === '' ) {
				$configPath = __DIR__ . '/../config/config.json';
			}
		}
		$json = file_get_contents( $configPath );
		if ( $json === false ) {
			throw new ServerError( 'This entry point is disabled: ' .
				"the configuration file $configPath is not present" );
		}
		$config = json_decode( $json, true );
		if ( $config === null ) {
			throw new ServerError( 'Error parsing JSON config file' );
		}

		$this->config = $config + self::DEFAULT_CONFIG;
	}

	/**
	 * Get a configuration variable
	 *
	 * @param string $name
	 * @return mixed
	 */
	private function getConfig( $name ) {
		if ( !array_key_exists( $name, $this->config ) ) {
			throw new ServerError( "The configuration variable \"$name\" is required, " .
				"but it is not present in the config file." );
		}
		return $this->config[$name];
	}

	/**
	 * Handle an exception.
	 *
	 * @param Throwable $exception
	 */
	private function handleException( $exception ) {
		if ( $this->logger ) {
			$this->logger->error(
				"Exception of class " . get_class( $exception ) . ': ' .
				$exception->getMessage(),
				[
					'trace' => $exception->getTraceAsString()
				]
			);
		}

		if ( headers_sent() ) {
			return;
		}

		if ( is_int( $exception->getCode() )
			&& $exception->getCode() >= 300 && $exception->getCode() < 600
		) {
			$code = $exception->getCode();
		} else {
			$code = 500;
		}
		http_response_code( $code );
		header( 'Content-Type: text/html; charset=utf-8' );
		$encMessage = $exception->getMessage();

		echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<title>Excimer UI Error $code</title>
</head>
<body>
<h1>Excimer UI Error $code</h1>
<p>
$encMessage
</p>
</body>
</html>

HTML;
	}

	/**
	 * Set up logging based on current configuration.
	 */
	private function setupLogger() {
		$this->logger = new Logger( 'excimer' );
		$this->logger->pushProcessor( new PsrLogMessageProcessor );
		$formatter = new LineFormatter( $this->getConfig( 'logFormat' ) );
		$jsonFormatter = new JsonFormatter( JsonFormatter::BATCH_MODE_NEWLINES );

		if ( strlen( $this->getConfig( 'logFile' ) ) ) {
			$handler = new StreamHandler( $this->getConfig( 'logFile' ) );
			$handler->setFormatter( $formatter );
			$this->logger->pushHandler( $handler );
		}
		if ( strlen( $this->getConfig( 'jsonLogFile' ) ) ) {
			$handler = new StreamHandler( $this->getConfig( 'jsonLogFile' ) );
			$handler->setFormatter( $jsonFormatter );
			$this->logger->pushHandler( $handler );
		}
		if ( $this->getConfig( 'logToStderr' ) ) {
			$handler = new StreamHandler( 'php://stderr' );
			$handler->setFormatter( $formatter );
			$this->logger->pushHandler( $handler );
		}
		if ( $this->getConfig( 'jsonLogToStderr' ) ) {
			$handler = new StreamHandler( 'php://stderr' );
			$handler->setFormatter( $jsonFormatter );
			$this->logger->pushHandler( $handler );
		}
		if ( $this->getConfig( 'logToSyslog' ) ) {
			$this->logger->pushHandler(
				new SyslogHandler( $this->getConfig( 'syslogIdent' ) ) );
		}
	}

	/**
	 * healthz action
	 */
	private function showHealth() {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "excimer-ui\n";
	}

	/**
	 * Connect to the database
	 *
	 * @return PDO
	 */
	private function getDB(): PDO {
		return new PDO(
			$this->getConfig( 'dsn' ),
			$this->getConfig( 'dbUser' ),
			$this->getConfig( 'dbPassword' ),
			[ PDO::ATTR_PERSISTENT => true ]
		);
	}

	/**
	 * Get a POST parameter
	 *
	 * @param string $name
	 * @return string
	 * @throws ServerError
	 */
	private function getPostParam( $name ): string {
		$post = $this->request->getParsedBody();
		if ( isset( $post[$name] ) ) {
			if ( is_string( $post[$name] ) ) {
				return $post[$name];
			} else {
				throw new ServerError( "Unexpected array POST parameter \"$name\"", 400 );
			}
		} else {
			throw new ServerError( "Missing POST parameter \"$name\"", 400 );
		}
	}

	/**
	 * Hash the request ID to generate a profile ID, in an identical manner to the client
	 *
	 * @param string $id
	 * @return string
	 */
	private function hash( $id ) {
		$key = $this->getConfig( 'hashKey' );
		if ( $key !== null ) {
			$hash = hash_hmac( 'sha512', $id, $key );
		} else {
			$hash = hash( 'sha512', $id );
		}
		return substr( $hash, 0, (int)$this->getConfig( 'profileIdLength' ) );
	}

	/**
	 * Handle the ingest action. Take data supplied as POST parameters and
	 * write it to the database. Purge expired database rows.
	 *
	 * @param string[] $pathParts
	 */
	private function ingest( $pathParts ) {
		if ( count( $pathParts ) !== 1 ) {
			throw new ServerError( "Invalid path format", 404 );
		}

		if ( $this->getConfig( 'asyncIngest' )
			&& function_exists( 'fastcgi_finish_request' )
		) {
			header( 'HTTP/1.1 202 Accepted' );
			fastcgi_finish_request();
		}

		$id = rawurldecode( $pathParts[0] );
		$name = $this->getPostParam( 'name' );
		$requestInfo = $this->getPostParam( 'request' );
		$requestId = $this->getPostParam( 'requestId' );
		$period = (float)$this->getPostParam( 'period' );
		$speedscope_deflated = $this->getPostParam( 'speedscope_deflated' );

		$expectedLength = (int)$this->getConfig( 'profileIdLength' );
		if ( strlen( $id ) !== $expectedLength ) {
			throw new ServerError( "Profile ID must have length $expectedLength", 400 );
		}
		if ( !hash_equals( $this->hash( $requestId ), $id ) ) {
			throw new ServerError(
				"Request ID and profile ID do not match, is hashKey configured incorrectly?",
				400 );
		}

		$db = $this->getDB();
		$st = $db->prepare(
			'INSERT INTO excimer_report ' .
			'(request_id, name, period_us, request_info, speedscope_deflated) ' .
			'VALUES (:request_id, :name, :period_us, :request_info, :speedscope_deflated)'
		);
		$st->execute( [
			':request_id' => $id,
			':name' => $name,
			':period_us' => (int)round( $period * 1e6 ),
			':request_info' => $requestInfo,
			':speedscope_deflated' => $speedscope_deflated
		] );

		$retention = (int)$this->getConfig( 'retentionDays' );
		if ( $retention ) {
			$st = $db->prepare(
				'DELETE FROM excimer_report ' .
				'WHERE created < DATE_SUB(NOW(), INTERVAL ? DAY)'
			);
			$st->execute( [ $retention ] );
		}
	}

	/**
	 * Handle the fetch action. Deliver JSON for speedscope to consume.
	 *
	 * @param string[] $pathParts
	 */
	private function fetch( $pathParts ) {
		if ( count( $pathParts ) !== 1 ) {
			throw new ServerError( "Invalid path format", 404 );
		}
		$id = rawurldecode( $pathParts[0] );
		$db = $this->getDB();
		$st = $db->prepare( 'SELECT speedscope_deflated ' .
			'FROM excimer_report WHERE request_id=?' );
		$st->execute( [ $id ] );
		if ( $st->rowCount() === 0 ) {
			throw new ServerError( "ID not found", 404 );
		} elseif ( $st->rowCount() === 1 ) {
			$row = $st->fetchObject();
			$result = $row->speedscope_deflated;
			$deflated = true;
		} else {
			$speedscopes = [];
			while ( ( $row = $st->fetchObject() ) !== false ) {
				$speedscopes[] = json_decode( gzinflate( $row->speedscope_deflated ), true );
			}
			$merged = $this->mergeProfiles( $speedscopes );
			header( 'Content-Type: application/json' );
			$result = json_encode(
				$merged,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			$deflated = false;
		}

		header( 'Content-Type: application/json' );
		header( 'X-Content-Type-Options: nosniff' );

		$encodings = array_map( 'trim',
			explode( ',', $this->request->getHeaderLine( 'Accept-Encoding' ) ) );
		if ( in_array( 'deflate', $encodings ) ) {
			header( 'Content-Encoding: deflate' );
			if ( $deflated ) {
				$out = $result;
			} else {
				$out = gzdeflate( $result );
			}
		} elseif ( $deflated ) {
			$out = gzinflate( $result );
		} else {
			$out = $result;
		}
		// @phan-suppress-next-line SecurityCheck-XSS -- it's not HTML...
		echo $out;
	}

	/**
	 * Merge decoded speedscope profile files
	 *
	 * @param array $speedscopes
	 * @return array
	 */
	private function mergeProfiles( $speedscopes ) {
		// Merge frames
		$frames = [];
		$indexesByKey = [];
		foreach ( $speedscopes as $speedscope ) {
			foreach ( $speedscope['shared']['frames'] as $frame ) {
				$key = $frame['name'] . "\0" . ( $frame['file'] ?? '' );
				if ( !isset( $indexesByKey[$key] ) ) {
					$index = count( $frames );
					$frames[] = $frame;
					$indexesByKey[$key] = $index;
				}
			}
		}

		// Rewrite profiles with updated frame indexes
		$newProfiles = [];
		foreach ( $speedscopes as $speedscope ) {
			foreach ( $speedscope['profiles'] as $profile ) {
				$newSamples = [];
				foreach ( $profile['samples'] as $sample ) {
					$newSample = [];
					foreach ( $sample as $frameIndex ) {
						$frame = $speedscope['shared']['frames'][$frameIndex];
						$key = $frame['name'] . "\0" . ( $frame['file'] ?? '' );
						$newSample[] = $indexesByKey[$key];
					}
					$newSamples[] = $newSample;
				}
				$newProfiles[] = [
					'type' => $profile['type'],
					'name' => $profile['name'],
					'unit' => $profile['unit'],
					'startValue' => $profile['startValue'],
					'endValue' => $profile['endValue'],
					'samples' => $newSamples,
					'weights' => $profile['weights']
				];
			}
		}
		return [
			'$schema' => 'https://www.speedscope.app/file-format-schema.json',
			'shared' => [ 'frames' => $frames ],
			'profiles' => $newProfiles,
			'exporter' => 'ExcimerUI server',
		];
	}

	/**
	 * Handle the speedscope action. Deliver a speedscope asset from the build
	 * directory.
	 *
	 * @param string[] $pathParts
	 */
	private function showSpeedscope( $pathParts ) {
		if ( count( $pathParts ) !== 1 ) {
			throw new ServerError( "Invalid speedscope path", 404 );
		}
		$path = $pathParts[0];
		if ( $path === '' ) {
			$path = 'index.html';
		}
		if ( ( $path[0] ?? '' ) === '.' ) {
			throw new ServerError( "Invalid speedscope path", 403 );
		}

		$absPath = $this->getSpeedscopePath( $path );
		if ( !is_file( $absPath ) ) {
			throw new ServerError( "speedscope path not found", 404 );
		}

		$type = $this->getTypeFromExtension( $path );
		header( 'Content-Type: ' . $type );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $absPath );
	}

	/**
	 * Convert a relative speedscope path to an absolute path
	 *
	 * @param string $relPath
	 * @return string
	 */
	private function getSpeedscopePath( $relPath ) {
		$base = $this->getConfig( 'speedscopePath' )
			?? __DIR__ . '/../lib/speedscope';
		return $base . '/' . $relPath;
	}

	/**
	 * Get the MIME type for a file extension. We only need to handle the extensions
	 * which are in a speedscope build.
	 *
	 * @param string $path
	 * @return string
	 */
	private function getTypeFromExtension( $path ) {
		$dotPos = strrpos( $path, '.' );
		$extension = $dotPos === false ? '' : substr( $path, $dotPos + 1 );
		return [
			'css' => 'text/css',
			'html' => 'text/html; charset=utf-8',
			'js' => 'application/javascript',
			'json' => 'application/json',
			'png' => 'image/png',
			'ttf' => 'application/font-sfnt',
			'txt' => 'text/plain',
		][$extension] ?? 'application/octet-stream';
	}

	/**
	 * Make a self-referential URL
	 *
	 * @param string $extra The suffix to add
	 * @return string
	 */
	private function makeUrl( $extra ) {
		$url = $this->getConfig( 'url' );
		if ( $url[-1] !== '/' ) {
			$url .= '/';
		}
		$url .= $extra;
		return $url;
	}

	/**
	 * Handle the profile action. Redirect to speedscope with the fetch URL in
	 * the fragment.
	 *
	 * @param string[] $pathParts
	 */
	private function showProfile( $pathParts ) {
		if ( count( $pathParts ) !== 1 ) {
			throw new ServerError( "Invalid profile path", 404 );
		}
		$url = $this->makeUrl( 'speedscope/#profileURL=' .
			rawurlencode( $this->makeUrl( 'fetch/' . $pathParts[0] ) )
		);
		$encUrl = htmlspecialchars( $url );
		echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<title>Excimer UI profile</title>
<style>
body {
	margin: 0;
	padding: 0;
	overflow: hidden;
}
#speedscope {
	width: 100%;
	height: 100vh;
	border: 0;
}
</style>
</head>
<body>
<iframe id="speedscope" src="$encUrl"></iframe>
<script>
document.getElementById('speedscope').focus();
</script>
</body>
</html>
HTML;
	}
}
