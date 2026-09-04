# Qliro contract fixtures

Byte copies of the canonical `GetOrder` fixtures the PIS repository pins, taken from
`pis/tests/Pis.Tests/contract-fixtures/` at commit 597e722. They are the wire shapes PIS records
against the Qliro sandbox, so the module's tests read the same payloads rather than payloads we
invented.

Re-copy them when PIS updates its own, nothing keeps the two in step automatically.

Worth knowing before reading them: `PaymentMethod.PaymentMethodName` carries the product
(`QLIRO_INVOICE`, `CREDITCARDS`, `QLIROPAYLATER_INVOICE14`) and `PaymentMethod.PaymentTypeCode`
carries the instrument behind it (`INVOICE`, `MASTERCARD`, and on live orders numeric values such
as `704`). Neither is a human readable label.
