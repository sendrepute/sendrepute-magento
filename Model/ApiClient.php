<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Model;

class ApiClient
{
    public const ENDPOINT = 'https://www.sendrepute.com/api/v1/classify';
    public const MAX_RESPONSE_BYTES = 1048576;

    /**
     * @param array{sender:string,subject:string,body:string} $payload
     * @return array{requestId:string,model:string,probability:float,label:string,chargedMillicents:int,replayed:bool}
     */
    public function classify(array $payload): array
    {
        $token = getenv('SENDREPUTE_API_TOKEN');
        if (!is_string($token) || $token === '' || preg_match('/[\r\n]/', $token)) {
            throw new \RuntimeException('SENDREPUTE_API_TOKEN is missing or invalid.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $this->validate($this->perform($json, $token));
    }

    /**
     * One bounded TLS request, with no redirects and no retry.
     *
     * @return array<string,mixed>
     */
    protected function perform(string $json, string $token): array
    {
        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialize the SendRepute request.');
        }
        $response = '';
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
        } finally {
            curl_close($handle);
        }
        if ($ok === false) {
            throw new \RuntimeException('SendRepute TLS request failed: ' . ($error !== '' ? $error : 'response limit exceeded'));
        }
        if ($status !== 200) {
            throw new \RuntimeException('SendRepute returned HTTP ' . $status . '.');
        }
        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('SendRepute returned invalid JSON.', 0, $e);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('SendRepute response is not an object.');
        }
        return $decoded;
    }

    /**
     * Exact public POST /v1/classify response envelope validation.
     *
     * @param array<string,mixed> $value
     * @return array{requestId:string,model:string,probability:float,label:string,chargedMillicents:int,replayed:bool}
     */
    public function validate(array $value): array
    {
        $this->exactKeys($value, ['requestId', 'model', 'result', 'billing'], 'response');
        $models = ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'];
        if (!is_string($value['requestId']) || $value['requestId'] === '' || strlen($value['requestId']) > 128
            || !is_string($value['model']) || !in_array($value['model'], $models, true)
            || !is_array($value['result']) || !is_array($value['billing'])) {
            throw new \RuntimeException('SendRepute response envelope is invalid.');
        }
        $result = $value['result'];
        $requiredResult = [
            'label', 'spamProbability', 'confidence', 'reasons', 'flaggedTerms',
            'analyzedFields', 'modelVersion', 'analyzedAt',
        ];
        $allowedResult = array_merge($requiredResult, ['flaggedTermCount', 'contentAudit']);
        foreach ($requiredResult as $key) {
            if (!array_key_exists($key, $result)) {
                throw new \RuntimeException('SendRepute result is missing ' . $key . '.');
            }
        }
        foreach (array_keys($result) as $key) {
            if (!in_array($key, $allowedResult, true)) {
                throw new \RuntimeException('SendRepute result has an unexpected field.');
            }
        }
        $probability = $result['spamProbability'];
        if ((!is_float($probability) && !is_int($probability))
            || !is_finite((float) $probability) || $probability < 0 || $probability > 1
            || !in_array($result['label'], ['inbox', 'spam'], true)
            || !in_array($result['confidence'], ['low', 'medium', 'high'], true)
            || !is_array($result['reasons']) || !is_array($result['flaggedTerms'])
            || !is_array($result['analyzedFields']) || !is_string($result['modelVersion'])
            || !is_string($result['analyzedAt'])) {
            throw new \RuntimeException('SendRepute classification result is invalid.');
        }
        foreach ($result['reasons'] as $reason) {
            $this->validateReason($reason);
        }
        $this->validateStringArray($result['flaggedTerms'], 'flaggedTerms');
        $this->validateStringArray($result['analyzedFields'], 'analyzedFields');
        if (array_key_exists('flaggedTermCount', $result)) {
            $this->nonNegativeInteger($result['flaggedTermCount'], 'flaggedTermCount');
        }
        if (array_key_exists('contentAudit', $result)) {
            $this->validateContentAudit($result['contentAudit']);
        }
        $billing = $value['billing'];
        $this->exactKeys($billing, ['chargedMillicents', 'replayed'], 'billing');
        if (!is_int($billing['chargedMillicents']) || $billing['chargedMillicents'] < 0
            || !is_bool($billing['replayed'])) {
            throw new \RuntimeException('SendRepute billing receipt is invalid.');
        }
        return [
            'requestId' => $value['requestId'],
            'model' => $value['model'],
            'probability' => (float) $probability,
            'label' => $result['label'],
            'chargedMillicents' => $billing['chargedMillicents'],
            'replayed' => $billing['replayed'],
        ];
    }

    private function exactKeys(array $value, array $keys, string $name): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new \RuntimeException('SendRepute ' . $name . ' fields do not match the public contract.');
        }
    }

    private function validateReason($reason): void
    {
        if (!is_array($reason)) {
            throw new \RuntimeException('SendRepute reason is not an object.');
        }
        $this->exactKeys($reason, ['signal', 'detail', 'weight'], 'reason');
        if (!is_string($reason['signal']) || !is_string($reason['detail'])
            || (!$this->finiteNumber($reason['weight']))) {
            throw new \RuntimeException('SendRepute reason is invalid.');
        }
    }

    private function validateContentAudit($audit): void
    {
        if (!is_array($audit)) {
            throw new \RuntimeException('SendRepute contentAudit is not an object.');
        }
        $required = [
            'score', 'grade', 'summary', 'counts', 'totalIssues', 'criticalCount',
            'warningCount', 'suggestionCount', 'issues', 'goodPractices', 'inputTruncated',
        ];
        $allowed = array_merge($required, ['homoglyphTerms']);
        foreach ($required as $key) {
            if (!array_key_exists($key, $audit)) {
                throw new \RuntimeException('SendRepute contentAudit is missing ' . $key . '.');
            }
        }
        foreach (array_keys($audit) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new \RuntimeException('SendRepute contentAudit has an unexpected field.');
            }
        }
        $score = $this->nonNegativeInteger($audit['score'], 'contentAudit.score');
        if ($score > 100 || !in_array($audit['grade'], ['A', 'B', 'C', 'D', 'F'], true)
            || !in_array($audit['summary'], ['fix_critical', 'fix_warnings', 'review_suggestions', 'looks_good'], true)
            || !is_bool($audit['inputTruncated'])) {
            throw new \RuntimeException('SendRepute contentAudit summary is invalid.');
        }
        if (!is_array($audit['counts'])) {
            throw new \RuntimeException('SendRepute contentAudit counts are invalid.');
        }
        $this->exactKeys($audit['counts'], ['words', 'links', 'images', 'triggerPhrases'], 'contentAudit.counts');
        foreach ($audit['counts'] as $key => $count) {
            $this->nonNegativeInteger($count, 'contentAudit.counts.' . $key);
        }
        foreach (['totalIssues', 'criticalCount', 'warningCount', 'suggestionCount'] as $key) {
            $this->nonNegativeInteger($audit[$key], 'contentAudit.' . $key);
        }
        if (!is_array($audit['issues']) || count($audit['issues']) > 50
            || !is_array($audit['goodPractices']) || count($audit['goodPractices']) > 20) {
            throw new \RuntimeException('SendRepute contentAudit item limits are invalid.');
        }
        foreach ($audit['issues'] as $issue) {
            $this->validateAuditIssue($issue);
        }
        foreach ($audit['goodPractices'] as $practice) {
            if (!is_array($practice)) {
                throw new \RuntimeException('SendRepute good practice is not an object.');
            }
            $this->exactKeys($practice, ['code', 'category'], 'contentAudit.goodPractice');
            if (!is_string($practice['code']) || !$this->auditCategory($practice['category'])) {
                throw new \RuntimeException('SendRepute good practice is invalid.');
            }
        }
        if (array_key_exists('homoglyphTerms', $audit)) {
            if (!is_array($audit['homoglyphTerms']) || count($audit['homoglyphTerms']) > 20) {
                throw new \RuntimeException('SendRepute homoglyphTerms is invalid.');
            }
            $this->validateStringArray($audit['homoglyphTerms'], 'contentAudit.homoglyphTerms', 120);
        }
    }

    private function validateAuditIssue($issue): void
    {
        if (!is_array($issue)) {
            throw new \RuntimeException('SendRepute content audit issue is not an object.');
        }
        $this->exactKeys($issue, ['code', 'category', 'severity', 'deduction', 'evidence'], 'contentAudit.issue');
        $deduction = $this->nonNegativeInteger($issue['deduction'], 'contentAudit.issue.deduction');
        if (!is_string($issue['code']) || !$this->auditCategory($issue['category'])
            || !in_array($issue['severity'], ['critical', 'warning', 'suggestion'], true)
            || $deduction > 100 || !is_string($issue['evidence']) || strlen($issue['evidence']) > 200) {
            throw new \RuntimeException('SendRepute content audit issue is invalid.');
        }
    }

    private function auditCategory($value): bool
    {
        return in_array($value, ['subject', 'content', 'links', 'structure', 'compliance'], true);
    }

    private function validateStringArray(array $values, string $name, ?int $maxLength = null): void
    {
        foreach ($values as $value) {
            if (!is_string($value) || ($maxLength !== null && strlen($value) > $maxLength)) {
                throw new \RuntimeException('SendRepute ' . $name . ' contains an invalid value.');
            }
        }
    }

    private function nonNegativeInteger($value, string $name): int
    {
        if (!$this->finiteNumber($value) || $value < 0 || floor((float) $value) !== (float) $value) {
            throw new \RuntimeException('SendRepute ' . $name . ' is not a non-negative integer.');
        }
        return (int) $value;
    }

    private function finiteNumber($value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}