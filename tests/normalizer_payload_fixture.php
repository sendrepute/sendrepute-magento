<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Tests;

use SendRepute\MailAdapter\Model\DisplayedEmail;

require __DIR__ . '/bootstrap.php';

$extractor = new DisplayedEmail();
$encoded = base64_encode(str_repeat('dangerous-visible-phrase ', 30));
$safe = $extractor->extract(new Message([new Part('text/plain', $encoded, '8bit')]));
$cssRaw = "visible line\nselector { dangerous-css-marker }\nlater line";
$cssRejected = false;
try {
    $extractor->extract(new Message([new Part('text/plain', $cssRaw, '8bit')]));
} catch (\RuntimeException $e) {
    $cssRejected = str_contains($e->getMessage(), 'CSS-block');
}
echo json_encode([
    'encodedLiteral' => $encoded,
    'safePayload' => $safe['body'],
    'cssRaw' => $cssRaw,
    'cssRejected' => $cssRejected,
], JSON_THROW_ON_ERROR);