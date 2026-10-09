<?php
declare(strict_types=1);

$path = dirname(__DIR__, 3) . '/artifacts/api-server/src/customer-api-openapi.json';
$schema = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
$post = $schema['paths']['/v1/classify']['post'] ?? null;
$input = $schema['components']['schemas']['CustomerClassificationInput'] ?? null;
$response = $schema['components']['schemas']['CustomerClassificationResponse'] ?? null;
if (!is_array($post) || ($post['operationId'] ?? '') !== 'classifyCustomerEmail'
    || ($input['required'] ?? null) !== ['sender', 'subject', 'body']
    || ($input['additionalProperties'] ?? true) !== false
    || ($response['required'] ?? null) !== ['requestId', 'model', 'result', 'billing']
    || ($response['additionalProperties'] ?? true) !== false) {
    throw new RuntimeException('public POST /v1/classify contract drifted');
}
$client = file_get_contents(dirname(__DIR__) . '/Model/ApiClient.php');
if (!str_contains($client, "https://www.sendrepute.com/api/v1/classify")
    || !str_contains($client, "SENDREPUTE_API_TOKEN")) {
    throw new RuntimeException('fixed endpoint or environment token contract missing');
}
echo "Workspace public POST /v1/classify schema contract verified.\n";