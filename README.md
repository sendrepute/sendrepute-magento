> **Standalone source distribution:** this repository contains the integration runtime, documentation, and source packager. Upstream workspace/CMS/production-normalizer regression suites are deliberately not distributed here because they depend on private server code or isolated platform fixtures. Testing commands and historical verification evidence below describe upstream maintainer validation, not a self-contained test suite in this source-only checkout. No third-party registry publication is implied.

# SendRepute paid pre-send adapter for Magento Open Source

Version **0.1.1** is a Composer `magento2-module` for the exact Magento Open
Source patch releases **2.4.7-p5** and **2.4.7-p6**. It is not an Adobe
Marketplace listing and makes no compatibility claim for another release.

## Boundary and behavior

`TransportPlugin::aroundSendMessage()` obtains `TransportInterface::getMessage()`
at the final transport boundary. It classifies before calling the original
transport and then calls that same transport with the same message. It never
sends email itself and never reconstructs or mutates the message.

Classification only occurs when all of these are true:

1. `enabled` is on (off by default);
2. paid/content consent is on (off by default);
3. the exact template identifier is in **Opted-in template identifiers**; and
4. the same identifier is independently approved in **Independent safety
   approval for opted-in routes**.

The double approval applies to every route. Magento identifiers can be numeric
or arbitrary custom names, so the adapter never guesses whether a route is
security-critical from its spelling. Template options must also contain an
explicit store ID. That ID travels with the message to the final hook and every
consent, allowlist, threshold, and policy read uses that exact store scope;
missing store context means no paid call.

Risk policy defaults to **advisory**. API/validation failure policy independently
defaults to **preserve**. Setting either policy to block raises a Magento
`MailException`; it does not return silent success. Threshold is validated as a
finite number from 0 through 1.

Each selected message can incur a paid API classification. Classification is a
content signal, not an inbox-placement guarantee.

## Install

Use a Composer path or source repository; do not copy a marketplace package.
For a path repository whose directory is this module:

```json
{
  "repositories": [
    {"type": "path", "url": "../sendrepute-magento", "options": {"symlink": false}}
  ]
}
```

Then require the package and enable the module using the normal Magento
deployment process:

```sh
composer require sendrepute/magento-mail-adapter:0.1.1
bin/magento module:enable SendRepute_MailAdapter
bin/magento setup:upgrade
```

Set `SENDREPUTE_API_TOKEN` in the PHP-FPM/CLI server environment. The credential
has no admin setting and is never persisted in Magento configuration. Configure
Stores → Configuration → Services → SendRepute separately at each desired
scope. Template identifiers are exact values passed to
`TransportBuilder::setTemplateIdentifier()`. Both route lists are required even
for names that appear non-critical.

## Fixed API contract

The only paid request is:

`POST https://www.sendrepute.com/api/v1/classify`

with bearer authorization and JSON `sender` (display name only), `subject`, and
`body`. The response parser requires the nested `requestId`, `model`, `result`,
and `billing` envelope, including an integer `billing.chargedMillicents` and
boolean `billing.replayed`.

There is one HTTPS request, no automatic retry, no redirect following, a 2
second connection deadline, 5 second total deadline, certificate/host
verification, and a 1 MiB response cap.

## MIME safety

Attachments are preserved and never uploaded. The adapter reads actual
`EmailMessageInterface`, `MimeMessageInterface`, and `MimePartInterface`
objects. `getRawContent()` is Magento's original unencoded part source, so a
part configured as quoted-printable or base64 is not decoded a second time.
Stream-backed display content, nested multiparts, unsupported charsets and
transfer encodings are rejected before a paid request.

The API accepts one untyped body. Therefore, a message with more than one
displayed `text/plain`/`text/html` MIME part is rejected rather than approved
from one benign alternative. HTML is parsed locally with network access
disabled, and raw-text/hidden/style semantics are rejected. Literal
quoted-printable-looking text, MIME base64-header lookalikes, and angle brackets
are rejected or made inert before the server normalizer sees them. In
fail-preserve mode this rejection preserves the original send; in fail-block
mode it throws visibly.

## Verification evidence

Run `sh tests/run.sh`. It performs PHP syntax checks and offline tests for:

- typed MIME objects matching the official interfaces, actual
  `getRawContent()` behavior, HTML/plain/base64-configured fixtures, attachment
  exclusion, dual alternatives, raw-text tags, quoted-printable ambiguity, and
  stream rejection;
- the actual server `visibleEmailText` function against payloads produced by
  the real PHP extractor, including CSS-brace removal and a whole-body
  base64-looking literal longer than 300 characters;
- store-scoped consent correlation, mandatory independent second approval for
  numeric/custom/critical routes, stale-state cleanup after failed transport
  construction, and `WeakMap` cleanup when a successfully built transport is
  abandoned without sending;
- the real workspace OpenAPI `POST /v1/classify` request/response shape;
- nested classify and billing fixtures, malformed values, and exact envelopes;
- final `aroundSendMessage` hook source contract; and
- deterministic, allowlisted production ZIP contents and repeatable hash.

The framework evidence is pinned to official Magento commits
`dc87600a0ab002bb2b7ef573fe78cfc951edb36c` (2.4.7-p5) and
`b69acb3d4cb3720665c7829fd6390d3036a07b13` (2.4.7-p6).
`tests/contracts.json` records SHA-256 values of the four official mail
interfaces; both commits produce identical files. A full Magento installation
was not created, and the typed fixture is not represented as broader version
certification.

Build the deterministic source archive with `php scripts/package.php`. It
creates `dist/sendrepute-magento-0.1.1.zip`. The archive intentionally excludes
tests, scripts, caches, credentials, `vendor`, and development files.