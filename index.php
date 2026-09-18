<?php

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Aws\Result;
use Aws\Sdk;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\ServerRequest;
use M1ke\JsonExplore\JsonExplore;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require __DIR__.'/vendor/autoload.php';

require __DIR__.'/config.php';

const RELAY_PATH = '/relay/';

/**
 * @throws RuntimeException
 */
function insert(array $entity, string $table_name): Result{
	$client = getDynamo();
	$item_params = insertParams($entity, $table_name);

	try {
		return $client->putItem($item_params);
	}
	catch (DynamoDbException) {
		throw new RuntimeException('There was an error saving data to DynamoDB.');
	}
}

function insertParams(array $entity, string $table_name): array{
	$marshaler = new Marshaler();
	$item_marshal = $marshaler->marshalItem($entity);

	return ['TableName' => $table_name, 'Item' => $item_marshal];
}

function sqlDate(bool $use_microtime = false): string{
	$microtime = explode('.', (string)microtime(true))[1];

	return date('Y-m-d H:i:s').($use_microtime ? '.'.$microtime : '');
}

function getDynamo(): DynamoDbClient{
	$args = [
		'region' => AWS_REGION,
		'version' => 'latest',
	];

	return (new Sdk($args))->createDynamoDb();
}

function getLogger(): Logger{
	$log = new Logger('name');
	$log->pushHandler(new StreamHandler('php://stderr', Level::Info));

	return $log;
}

function logWebhook(string $body, ?JsonExplore $json, ?JsonExplore $query, ?JsonExplore $form, Logger $log): void{
	if (!$form && !$json && !$query){
		$log->info('No data in form, JSON input, or query string, will not save log');

		return;
	}

	$entity = [
		'id' => uniqid('', true),
		'datetime' => sqlDate(true),
		'json' => $json ? $body : null,
		'json_parsed' => $json?->asPathString(),
		'form' => $_POST ? json_encode($_POST) : null,
		'form_parsed' => $form?->asPathString(),
		'query' => $_GET ? json_encode($_GET) : '',
		'query_parsed' => $query?->asPathString(),
		'processed' => false,
		'ttl' => time()+TTL_SECONDS,
	];
	$entity = array_filter($entity, static fn($n) => $n!==null && $n!=='');

	insert($entity, LOG_TABLE);
}

function dataFromJson(string $json, Logger $log): ?JsonExplore{
	if (!$json){
		$log->info('Request body was empty');

		return null;
	}

	try {
		return JsonExplore::fromJson($json)->analyse();
	}
	catch (InvalidArgumentException) {
		$log->warning('JSON parse error with message: '.json_last_error_msg().". String content was: $json");

		return null;
	}
}

function dataFromQuery(array $query, Logger $log): ?JsonExplore{
	if (!$query){
		$log->info('Query string was empty');

		return null;
	}

	return JsonExplore::fromArray($query)->analyse();
}

function dataFromForm(array $form, Logger $log): ?JsonExplore{
	if (!$form){
		$log->info('Form body was empty');

		return null;
	}

	return JsonExplore::fromArray($form)->analyse();
}

function relay(ServerRequestInterface $request, array $config, Logger $log): void{
	$url = $config['url'];
	$body = json_encode($config['body']);
	$options = $config['options'] ?? null;

	$client = new Client();
	$headers = ['Content-Type' => 'application/json'];
	// Use this to trigger a pipeline
	$relay_request = new Request($request->getMethod(), $url, $headers, $body);

	try {
		$response = $client->send($relay_request, $options ?: []);

		$log->notice("Relay sent with response", responseLogContext($response));
	}
	catch (GuzzleException $e) {
		$response = $e instanceof ResponseException ? $e->getResponse() : null;
		if ($response){
			$log->warning("Request problem", responseLogContext($response));

			return;
		}

		$log->error($e->getMessage());
	}
}

function responseLogContext(ResponseInterface $response): array{
	return [
		'status' => $response->getStatusCode(),
		'body' => $response->getBody()->getContents(),
	];
}

$log = getLogger();

$request = ServerRequest::fromGlobals();

try {
	// Replace these with the $request item above
	$body = (string)file_get_contents('php://input');
	$json_explored = dataFromJson($body, $log);
	$query_explored = dataFromQuery($_GET, $log);
	$form_explored = dataFromForm($_POST, $log);

	logWebhook($body, $json_explored, $query_explored, $form_explored, $log);
}
catch (Throwable $e) {
	$log->error('Problem with logger: '.$e->getMessage());
}

$relay = json_decode($_ENV['RELAY'], true);
if (!$relay){
	// Relay isn't set up, we're done
	return;
}

try {
	$path = $request->getUri()->getPath();

	if (!str_starts_with($path, RELAY_PATH)){
		// This request isn't using relay
		return;
	}

	$relay_path = explode(RELAY_PATH, $path, 2)[1];
	$relay_config = $relay[$relay_path] ?? null;
	if (!$relay_config){
		// Request was trying to use relay but the path wasn't configured, this is likely not desired

		$log->error("No relay target set", ['relay_path' => $relay_path,]);

		return;
	}

	$log->info('Entering relay mode', [
		'relay_path' => $relay_path,
		'relay_config' => $relay_config,
	]);

	relay($request, $relay_config, $log);
}
catch (Throwable $e) {
	$log->error('Problem with relay: '.$e->getMessage());
}
