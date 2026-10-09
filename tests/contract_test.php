<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Tests;

use SendRepute\MailAdapter\Model\ApiClient;
use SendRepute\MailAdapter\Model\DisplayedEmail;

require __DIR__ . '/bootstrap.php';

$extractor = new DisplayedEmail();

$plain = $extractor->extract(new Message([
    new Part('text/plain', 'A literal hello', 'quoted-printable'),
    new Part('application/pdf', 'secret attachment', 'base64', 'attachment'),
]));
if ($plain['sender'] !== 'Store Team' || !str_contains($plain['body'], 'literal hello')
    || str_contains($plain['body'], 'secret attachment')) {
    throw new \RuntimeException('plain extraction or attachment exclusion failed');
}

$base64Source = $extractor->extract(new Message([
    // getRawContent is already the original unencoded Magento MimePart source.
    new Part('text/plain', 'Visible source from a base64-configured part', 'base64'),
]));
if (!str_contains($base64Source['body'], 'Visible source')) {
    throw new \RuntimeException('base64-configured raw source was not classified');
}

$html = $extractor->extract(new Message([
    new Part('text/html', '<p>Hello <a href="https://example.test/x">customer</a></p>'),
]));
if (!str_contains($html['body'], 'customer') || !str_contains($html['body'], 'link destination')) {
    throw new \RuntimeException('HTML visible text extraction failed');
}

expectFailure(fn() => $extractor->extract(new Message([
    new Part('text/plain', 'benign'),
    new Part('text/html', '<p>second alternative</p>'),
])), 'multiple displayed alternatives');
expectFailure(fn() => $extractor->extract(new Message([
    new Part('text/html', '<script>later fragment could be swallowed</script><p>safe</p>'),
])), 'raw-text HTML');
expectFailure(fn() => $extractor->extract(new Message([
    new Part('text/plain', '=3Cscript=3Ehidden=3C/script=3E'),
])), 'quoted-printable-looking displayed literal');
expectFailure(fn() => $extractor->extract(new Message([
    new Part('text/plain', "prefix=\r\nlater"),
])), 'quoted-printable soft line');
expectFailure(fn() => $extractor->extract(new Message([
    new Part('text/plain', "visible\nselector { hidden backend text }\nlater"),
])), 'CSS-brace normalization ambiguity');
expectFailure(fn() => $extractor->extract(new Message([
    new Part('text/plain', 'stream', '8bit', 'inline', 'utf-8', true),
])), 'stream-backed displayed part');

$fixture = [
    'requestId' => 'request_123',
    'model' => 'thor',
    'result' => [
        'label' => 'spam',
        'spamProbability' => 0.75,
        'confidence' => 'high',
        'reasons' => [],
        'flaggedTerms' => [],
        'analyzedFields' => ['subject', 'body'],
        'modelVersion' => '1',
        'analyzedAt' => '2026-01-01T00:00:00Z',
    ],
    'billing' => ['chargedMillicents' => 1000, 'replayed' => false],
];
$validated = (new ApiClient())->validate($fixture);
if ($validated['probability'] !== 0.75 || $validated['chargedMillicents'] !== 1000) {
    throw new \RuntimeException('nested public response was not preserved');
}
foreach ([-0.1, 1.1, NAN, INF, '0.5'] as $bad) {
    expectFailure(function () use ($fixture, $bad): void {
        $fixture['result']['spamProbability'] = $bad;
        (new ApiClient())->validate($fixture);
    }, 'invalid probability');
}
expectFailure(function () use ($fixture): void {
    $fixture['billing']['chargedMillicents'] = 1.0;
    (new ApiClient())->validate($fixture);
}, 'non-integer billing');
expectFailure(function () use ($fixture): void {
    $fixture['extra'] = true;
    (new ApiClient())->validate($fixture);
}, 'extra response field');
expectFailure(function () use ($fixture): void {
    $fixture['result']['reasons'] = [['signal' => 'x', 'detail' => 'y', 'weight' => '1']];
    (new ApiClient())->validate($fixture);
}, 'untyped reason');
expectFailure(function () use ($fixture): void {
    $fixture['result']['flaggedTerms'] = [['not' => 'a string']];
    (new ApiClient())->validate($fixture);
}, 'untyped flagged term');
expectFailure(function () use ($fixture): void {
    $fixture['result']['flaggedTermCount'] = 1.5;
    (new ApiClient())->validate($fixture);
}, 'fractional flagged term count');

$fixture['result']['contentAudit'] = [
    'score' => 95,
    'grade' => 'A',
    'summary' => 'looks_good',
    'counts' => ['words' => 10, 'links' => 1, 'images' => 0, 'triggerPhrases' => 0],
    'totalIssues' => 0,
    'criticalCount' => 0,
    'warningCount' => 0,
    'suggestionCount' => 0,
    'issues' => [],
    'goodPractices' => [['code' => 'clear', 'category' => 'content']],
    'homoglyphTerms' => [],
    'inputTruncated' => false,
];
(new ApiClient())->validate($fixture);
expectFailure(function () use ($fixture): void {
    $fixture['result']['contentAudit']['issues'] = [[
        'code' => 'x',
        'category' => 'content',
        'severity' => 'warning',
        'deduction' => 2,
        'evidence' => 'e',
        'extra' => true,
    ]];
    (new ApiClient())->validate($fixture);
}, 'content audit issue with extra property');
expectFailure(function () use ($fixture): void {
    $fixture['result']['contentAudit']['counts']['words'] = '10';
    (new ApiClient())->validate($fixture);
}, 'untyped content audit count');

echo "Typed Magento MIME and public classify fixtures verified; no paid call or send occurred.\n";