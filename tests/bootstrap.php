<?php
declare(strict_types=1);

namespace Magento\Framework\Mail {
    interface TransportInterface {
        public function sendMessage();
        public function getMessage();
    }
    interface MimePartInterface {
        public function getType(): string;
        public function getEncoding(): string;
        public function getDisposition(): string;
        public function getDescription(): string;
        public function getFileName(): string;
        public function getCharset(): string;
        public function getBoundary(): string;
        public function getLocation(): string;
        public function getLanguage(): string;
        public function getFilters(): array;
        public function isStream(): bool;
        public function getEncodedStream($endOfLine = "\r\n");
        public function getContent($endOfLine = "\r\n");
        public function getRawContent(): string;
        public function getHeadersArray($endOfLine = "\r\n"): array;
        public function getHeaders($endOfLine = "\r\n"): string;
    }
    interface MimeMessageInterface {
        public function getParts(): array;
        public function isMultiPart(): bool;
        public function getMessage(string $endOfLine = "\r\n"): string;
        public function getPartHeadersAsArray(int $partNum): array;
        public function getPartHeaders(int $partNum, string $endOfLine = "\r\n"): string;
        public function getPartContent(int $partNum, string $endOfLine = "\r\n"): string;
    }
    interface MailMessageInterface {}
    interface EmailMessageInterface extends MailMessageInterface {
        public function getEncoding(): string;
        public function getHeaders(): array;
        public function getFrom(): ?array;
        public function getTo(): array;
        public function getCc(): ?array;
        public function getBcc(): ?array;
        public function getReplyTo(): ?array;
        public function getSender(): ?Address;
        public function getSubject();
        public function getMessageBody(): MimeMessageInterface;
        public function getBodyText(): string;
        public function toString(): string;
    }
    final class Address {
        public function __construct(private string $name) {}
        public function getName(): string { return $this->name; }
    }
}

namespace Magento\Framework\Mail\Template {
    class TransportBuilder {}
}

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface {
        public function getValue($path = null, $scopeType = null, $scopeCode = null);
        public function isSetFlag($path, $scopeType = null, $scopeCode = null);
    }
}

namespace Magento\Framework\Exception {
    class MailException extends \Exception {}
}

namespace Magento\Store\Model {
    final class ScopeInterface {
        public const SCOPE_STORE = 'store';
    }
}

namespace Psr\Log {
    interface LoggerInterface {
        public function info($message, array $context = []);
        public function error($message, array $context = []);
    }
}

namespace SendRepute\MailAdapter\Tests {
    use Magento\Framework\Mail\Address;
    use Magento\Framework\Mail\EmailMessageInterface;
    use Magento\Framework\Mail\MimeMessageInterface;
    use Magento\Framework\Mail\MimePartInterface;

    require dirname(__DIR__) . '/Model/DisplayedEmail.php';
    require dirname(__DIR__) . '/Model/ApiClient.php';

    final class Part implements MimePartInterface {
        public function __construct(
            private string $type,
            private string $raw,
            private string $encoding = 'quoted-printable',
            private string $disposition = 'inline',
            private string $charset = 'utf-8',
            private bool $stream = false
        ) {}
        public function getType(): string { return $this->type; }
        public function getEncoding(): string { return $this->encoding; }
        public function getDisposition(): string { return $this->disposition; }
        public function getDescription(): string { return ''; }
        public function getFileName(): string { return ''; }
        public function getCharset(): string { return $this->charset; }
        public function getBoundary(): string { return ''; }
        public function getLocation(): string { return ''; }
        public function getLanguage(): string { return ''; }
        public function getFilters(): array { return []; }
        public function isStream(): bool { return $this->stream; }
        public function getEncodedStream($endOfLine = "\r\n") { return null; }
        public function getContent($endOfLine = "\r\n") { return $this->raw; }
        public function getRawContent(): string { return $this->raw; }
        public function getHeadersArray($endOfLine = "\r\n"): array { return []; }
        public function getHeaders($endOfLine = "\r\n"): string { return ''; }
    }
    final class Body implements MimeMessageInterface {
        public function __construct(private array $parts) {}
        public function getParts(): array { return $this->parts; }
        public function isMultiPart(): bool { return count($this->parts) > 1; }
        public function getMessage(string $endOfLine = "\r\n"): string { return ''; }
        public function getPartHeadersAsArray(int $partNum): array { return []; }
        public function getPartHeaders(int $partNum, string $endOfLine = "\r\n"): string { return ''; }
        public function getPartContent(int $partNum, string $endOfLine = "\r\n"): string { return ''; }
    }
    final class Message implements EmailMessageInterface {
        public function __construct(private array $parts, private string $subject = 'Subject') {}
        public function getEncoding(): string { return 'utf-8'; }
        public function getHeaders(): array { return []; }
        public function getFrom(): ?array { return [new Address('Store Team')]; }
        public function getTo(): array { return []; }
        public function getCc(): ?array { return null; }
        public function getBcc(): ?array { return null; }
        public function getReplyTo(): ?array { return null; }
        public function getSender(): ?Address { return null; }
        public function getSubject() { return $this->subject; }
        public function getMessageBody(): MimeMessageInterface { return new Body($this->parts); }
        public function getBodyText(): string { return ''; }
        public function toString(): string { return 'immutable-original'; }
    }

    function expectFailure(callable $callable, string $name): void {
        try {
            $callable();
        } catch (\RuntimeException $e) {
            return;
        }
        throw new \RuntimeException($name . ' was accepted');
    }
}