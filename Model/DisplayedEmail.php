<?php
declare(strict_types=1);

namespace SendRepute\MailAdapter\Model;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\MimePartInterface;

final class DisplayedEmail
{
    public const MAX_BODY_BYTES = 524288;

    /**
     * Extracts one unambiguous displayed MIME part without mutating the message.
     *
     * Magento MimePartInterface::getRawContent() is the unencoded source. We
     * deliberately refuse multiple displayed alternatives because the API has
     * one body and its normalizer may let one fragment consume another.
     *
     * @return array{sender:string,subject:string,body:string}
     */
    public function extract(EmailMessageInterface $message): array
    {
        $subject = $message->getSubject();
        if (!is_string($subject) || $subject === '') {
            throw new \RuntimeException('The final message has no supported subject.');
        }

        $displayed = [];
        foreach ($message->getMessageBody()->getParts() as $part) {
            if (!$part instanceof MimePartInterface) {
                throw new \RuntimeException('The final message contains an unsupported MIME part.');
            }
            $type = strtolower(trim(explode(';', $part->getType(), 2)[0]));
            $disposition = strtolower(trim($part->getDisposition()));
            if ($disposition === 'attachment') {
                continue;
            }
            if (str_starts_with($type, 'multipart/')) {
                throw new \RuntimeException('Nested multipart displayed content is unsupported.');
            }
            if (!in_array($type, ['text/plain', 'text/html'], true)) {
                continue;
            }
            if ($part->isStream()) {
                throw new \RuntimeException('Stream-backed displayed content is unsupported.');
            }
            $charset = strtolower(trim($part->getCharset()));
            if ($charset !== '' && !in_array($charset, ['utf-8', 'utf8', 'us-ascii'], true)) {
                throw new \RuntimeException('Displayed content charset is unsupported.');
            }
            $encoding = strtolower(trim($part->getEncoding()));
            if (!in_array($encoding, ['', '7bit', '8bit', 'quoted-printable', 'base64'], true)) {
                throw new \RuntimeException('Displayed content transfer encoding is unsupported.');
            }
            $raw = $part->getRawContent();
            if (!is_string($raw)) {
                throw new \RuntimeException('Displayed content is not a string.');
            }
            $displayed[] = [$type, $raw];
        }

        if (count($displayed) !== 1) {
            throw new \RuntimeException('Exactly one displayed text/plain or text/html MIME part is required.');
        }

        [$type, $raw] = $displayed[0];
        $body = $type === 'text/html' ? $this->htmlToInertText($raw) : $this->plainToInertText($raw, true);
        $safeSubject = $this->plainToInertText($subject, false);
        if ($safeSubject === '' || strlen($safeSubject) > 998 || $body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            throw new \RuntimeException('Displayed content is outside the classification size contract.');
        }

        $from = $message->getFrom();
        $sender = is_array($from) && isset($from[0]) ? trim((string) $from[0]->getName()) : '';
        if ($sender === '') {
            throw new \RuntimeException('A sender display name is required; an address is never substituted.');
        }
        $sender = $this->plainToInertText($sender, false);
        if ($sender === '' || strlen($sender) > 320) {
            throw new \RuntimeException('Sender display name is outside the classification contract.');
        }

        return ['sender' => $sender, 'subject' => $safeSubject, 'body' => $body];
    }

    public function plainToInertText(string $text, bool $body): string
    {
        if (preg_match('/[\x00\x0B\x0C]/', $text)) {
            throw new \RuntimeException('Displayed text contains unsupported controls.');
        }
        if ($body && (preg_match('/=\r?\n/', $text) || preg_match('/=[0-9A-Fa-f]{2}/', $text))) {
            throw new \RuntimeException('Displayed text is ambiguous with server quoted-printable decoding.');
        }
        if ($body && (str_contains($text, '{') || str_contains($text, '}'))) {
            // visibleEmailText removes CSS-looking brace blocks after transfer
            // decoding, even in plain text. Refuse instead of paying to analyze
            // a body different from what the recipient sees.
            throw new \RuntimeException('Displayed text is ambiguous with server CSS-block normalization.');
        }
        $text = str_replace(['<', '>'], [' [less-than] ', ' [greater-than] '], $text);
        if ($body) {
            $text = preg_replace(
                '/content-transfer-encoding(\s*:\s*base64)/i',
                'content transfer encoding$1',
                $text
            );
            if (!is_string($text)) {
                throw new \RuntimeException('Displayed text normalization failed.');
            }
            // Prevent whole-body and MIME-header-like base64 auto-detection.
            $text = '[_] ' . $text;
        }
        return $text;
    }

    private function htmlToInertText(string $html): string
    {
        if (preg_match('/<(?:style|script|template|svg|math|iframe|object)\b/i', $html)
            || preg_match('/\sstyle\s*=|\shidden(?:\s|=|>)/i', $html)) {
            throw new \RuntimeException('HTML uses unsupported display semantics.');
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><body>' . $html . '</body>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \RuntimeException('HTML could not be parsed safely.');
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            throw new \RuntimeException('HTML has no parseable body.');
        }
        $parts = [];
        $this->collectVisibleText($body, $parts);
        $text = html_entity_decode(implode(' ', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($text)) {
            throw new \RuntimeException('HTML normalization failed.');
        }
        return $this->plainToInertText(trim($text), true);
    }

    private function collectVisibleText(DOMNode $node, array &$parts): void
    {
        if ($node instanceof DOMText) {
            $parts[] = $node->nodeValue;
            return;
        }
        if (!$node instanceof DOMElement && !$node instanceof DOMDocument) {
            return;
        }
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['head', 'script', 'style', 'template', 'noscript', 'svg', 'math'], true)) {
                return;
            }
            if ($tag === 'img') {
                $parts[] = $node->getAttribute('alt');
                $parts[] = '[image source ' . $node->getAttribute('src') . ']';
            }
        }
        foreach ($node->childNodes as $child) {
            $this->collectVisibleText($child, $parts);
        }
        if ($node instanceof DOMElement && strtolower($node->tagName) === 'a') {
            $parts[] = '[link destination ' . $node->getAttribute('href') . ']';
        }
        if ($node instanceof DOMElement && in_array(
            strtolower($node->tagName),
            ['br', 'p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            true
        )) {
            $parts[] = "\n";
        }
    }
}