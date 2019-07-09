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

/**
 * @param DynamoDbClient $client
 * @param array $entity
 * @param string $table_name
 * @return Result
 * @throws Exception
 */
function insert(DynamoDbClient $client, array $entity, string $table_name){
	$item_params = insertParams($entity, $table_name);

	try {
		return $client->putItem($item_params);
	}
	catch (DynamoDbException $e) {
		throw new Exception('There was an error saving data to DynamoDB.');
	}
}

/**
 * @param array $entity
 * @param string $table_name
 * @return array
 */
function insertParams(array $entity, string $table_name):array{
	$marshaler = new Marshaler();
	$item = $entity;
	// Not connected to our app time as its a date relating
	// to when AWS with auto delete the row, meaning it needs
	// to be whatever time the row is being inserted
	$item['ttl'] = (new \DateTimeImmutable())->add(new DateInterval('P1D'))->getTimestamp();
	$item_marshal = $marshaler->marshalItem($item);

	$item_params = ['TableName' => $table_name, 'Item' => $item_marshal];

	return $item_params;
}

$log = new Logger('name');
$log->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

$args = [
	'region' => 'eu-west-1',
	'version' => 'latest',
];

$sdk = new Sdk($args);

$dynamo = $sdk->createDynamoDb();

$json = file_get_contents('php://input');

try {
	$explore = JsonExplore::fromJson($json)->analyse();
}
catch (InvalidArgumentException $e) {
	$log->warning("JSON parse error with message: ".json_last_error_msg().". String content was: $json");

	return;
}

$entity = [
	'id' => uniqid(),
	'datetime' => date('Y-m-d H:i:s'),
	'json' => $json,
	'parsed' => $explore->asPathString(),
	'processed' => false,
];

insert($dynamo, $entity, 'webhook-logger');
