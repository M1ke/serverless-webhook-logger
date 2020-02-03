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
 * @param DynamoDbClient $client
 * @param array $entity
 * @param string $table_name
 * @return Result
 * @throws RuntimeException
 */
function insert(DynamoDbClient $client, array $entity, string $table_name){
	$item_params = insertParams($entity, $table_name);

	try {
		return $client->putItem($item_params);
	}
	catch (DynamoDbException $e) {
		throw new RuntimeException('There was an error saving data to DynamoDB.');
	}
}

/**
 * @param array $entity
 * @param string $table_name
 * @return array
 */
function insertParams(array $entity, string $table_name):array{
	$marshaler = new Marshaler();
	$item_marshal = $marshaler->marshalItem($entity);

	return ['TableName' => $table_name, 'Item' => $item_marshal];
}

/**
 * @return string
 */
function sqlDate(){
	return date('Y-m-d H:i:s');
}

/**
 * @return DynamoDbClient
 */
function getDynamo(){
	$args = [
		'region' => AWS_REGION,
		'version' => 'latest',
	];

	$sdk = new Sdk($args);

	return $sdk->createDynamoDb();
}

/**
 * @return Logger
 */
function getLogger(){
	$log = new Logger('name');
	$log->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

	return $log;
}

/**
 * @param DynamoDbClient $dynamo
 * @param string $json
 * @param JsonExplore $explore
 * @throws Exception
 */
function logWebhook(DynamoDbClient $dynamo, string $json, JsonExplore $explore){
	$entity = [
		'id' => uniqid('', true),
		'datetime' => sqlDate(),
		'json' => $json,
		'parsed' => $explore->asPathString(),
		'processed' => false,
		'ttl' => time()+TTL_SECONDS,
	];

	insert($dynamo, $entity, LOG_TABLE);
}

$log = getLogger();

$dynamo = getDynamo();

$json = file_get_contents('php://input');

try {
	$explore = JsonExplore::fromJson($json)->analyse();
}
catch (InvalidArgumentException $e) {
	$log->warning('JSON parse error with message: '.json_last_error_msg().". String content was: $json");

	return;
}

try {
	logWebhook($dynamo, $json, $explore);
}
catch (Exception $e) {
	$log->error($e->getMessage());
}
