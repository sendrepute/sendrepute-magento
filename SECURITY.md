# Security

Report suspected vulnerabilities privately to **security@sendrepute.com**.
Include module version, exact Magento patch release, configuration scope,
reproduction steps, and whether the message was preserved or blocked. Do not
include production API tokens or customer email content.

## Trust and data boundary

- The bearer token is accepted only from the server environment variable
  `SENDREPUTE_API_TOKEN`.
- The destination is fixed to
  `https://www.sendrepute.com/api/v1/classify`; redirects and retries are off.
- Only sender display name, subject, and one safely normalized displayed body
  are submitted. Recipient addresses, sender address, headers, attachments,
  transport object, and raw MIME message are not submitted.
- Enabled, paid consent, and two independent exact route approvals are all
  required in the message's explicitly correlated store scope. The second
  approval applies to every route because numeric/custom identifiers cannot be
  classified safely by name.
- Logs contain route and receipt metadata, never token or message content.
- Request-local route correlations use object-keyed `WeakMap` storage. An
  abandoned transport/message cannot leave a numeric object ID that a later,
  unrelated message could reuse.

Fail-preserve can allow an unclassified message when extraction, TLS, API, or
response validation fails. Fail-block prevents the transport call by throwing a
Magento mail exception. This is separate from advisory/block risk policy.

Messages containing multiple displayed alternatives are deliberately
unsupported because the endpoint accepts one body. Do not weaken this check or
approve a multipart message based on only one part.

Displayed text containing CSS braces or transfer-decoding ambiguity is also
rejected before payment because the server normalizer could otherwise remove
visible content. Long base64-looking displayed literals are prefixed into an
inert payload; a regression test passes the actual extractor output through the
actual server `visibleEmailText` function.