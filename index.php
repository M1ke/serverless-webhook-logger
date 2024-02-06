<?php

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Aws\Result;
use Aws\Sdk;
use M1ke\JsonExplore\JsonExplore;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

require __DIR__.'/vendor/autoload.php';

require __DIR__.'/config.php';

/**
 * @param array $entity
 * @param string $table_name
 * @return Result
 * @throws RuntimeException
 */
function insert(array $entity, string $table_name){
	$client = getDynamo();
	$item_params = insertParams($entity, $table_name);

	try {
		return $client->putItem($item_params);
	}
	catch (DynamoDbException $e) {
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
	$log->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

	return $log;
}

function logWebhook(string $body, ?JsonExplore $json, ?JsonExplore $query, ?JsonExplore $form, Logger $log){
	if (!$form && !$json && !$query){
		$log->info('No data in form, JSON input, or query string, will not save log');

		return;
	}

	$entity = [
		'id' => uniqid('', true),
		'datetime' => sqlDate(true),
		'json' => $json ? $body : null,
		'json_parsed' => $json ? $json->asPathString() : null,
		'form' => $_POST ? json_encode($_POST) : null,
		'form_parsed' => $form ? $form->asPathString() : null,
		'query' => $_GET ? json_encode($_GET) : '',
		'query_parsed' => $query ? $query->asPathString() : null,
		'processed' => false,
		'ttl' => time()+TTL_SECONDS,
	];
	$entity = array_filter($entity, static fn ($n) => $n!==null && $n!=='');

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
	catch (InvalidArgumentException $e) {
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

$log = getLogger();
try {
	$body = (string)file_get_contents('php://input');
	$json_explored = dataFromJson($body, $log);
	$query_explored = dataFromQuery($_GET, $log);
	$form_explored = dataFromForm($_POST, $log);

	logWebhook($body, $json_explored, $query_explored, $form_explored, $log);
}
catch (Exception $e) {
	$log->error($e->getMessage());
}
