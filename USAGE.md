# SDKs

Official clients for the Vive messaging API. Each one gives you typed requests and
responses, error classes you can branch on, automatic retries with backoff for `429` and
`5xx`, and constant-time webhook signature verification.

All five produce byte-identical webhook signatures, verified against the server's own
implementation.

| Language | Package | Source |
|---|---|---|
| Node / TypeScript | `@techardentlabs/vive-sdk` | [`sdks/node`](../sdks/node) |
| Python | `vive` | [`sdks/python`](../sdks/python) |
| Go | `github.com/techardentlabs/vive-go` | [`sdks/go`](../sdks/go) |
| PHP | `vive/sdk` | [`sdks/php`](../sdks/php) |
| Ruby | `vive` | [`sdks/ruby`](../sdks/ruby) |

Every client reads `VIVE_API_KEY` from the environment, and `VIVE_BASE_URL` if you're
pointing at something other than production.

## Node / TypeScript

```bash
npm install @techardentlabs/vive-sdk
```

```ts
import { Vive, ViveError, ViveRateLimitError } from "@techardentlabs/vive-sdk";

const vive = new Vive(); // reads VIVE_API_KEY

const message = await vive.sendText({
  to: "15551234567",
  text: "Your order shipped.",
  idempotencyKey: "order-4417-shipped",
});

console.log(message.id, message.status);
```

Branch on the error type:

```ts
try {
  await vive.sendText({ to, text });
} catch (err) {
  if (err instanceof ViveRateLimitError) {
    // already retried; you are genuinely over the limit
  } else if (err instanceof ViveError && err.code === "outside_service_window") {
    await vive.sendTemplate({ to, templateName: "order_shipped", templateParams: ["4417"] });
  } else {
    throw err;
  }
}
```

## Python

```bash
pip install vive
```

```python
from vive import Vive, ViveError

vive = Vive()  # reads VIVE_API_KEY

message = vive.send_text(
    to="15551234567",
    text="Your order shipped.",
    idempotency_key="order-4417-shipped",
)
print(message.id, message.status)
```

```python
try:
    vive.send_text(to=to, text=text)
except ViveError as e:
    if e.code == "outside_service_window":
        vive.send_template(to=to, template_name="order_shipped", template_params=["4417"])
    else:
        raise
```

## Go

```bash
go get github.com/techardentlabs/vive-go
```

```go
client, err := vive.New("") // reads VIVE_API_KEY
if err != nil {
	log.Fatal(err)
}

msg, err := client.SendText(ctx, vive.SendTextInput{
	To:             "15551234567",
	Text:           "Your order shipped.",
	IdempotencyKey: "order-4417-shipped",
})

var apiErr *vive.Error
if errors.As(err, &apiErr) && apiErr.Code == "outside_service_window" {
	msg, err = client.SendTemplate(ctx, vive.SendTemplateInput{
		To:             "15551234567",
		TemplateName:   "order_shipped",
		TemplateParams: []string{"4417"},
	})
}
```

## PHP

```bash
composer require vive/sdk
```

```php
use Vive\Vive;
use Vive\ViveException;

$vive = new Vive(); // reads VIVE_API_KEY

try {
    $message = $vive->sendText('15551234567', 'Your order shipped.', 'order-4417-shipped');
} catch (ViveException $e) {
    if ($e->code() === 'outside_service_window') {
        $vive->sendTemplate('15551234567', 'order_shipped', 'en_US', ['4417']);
    } else {
        throw $e;
    }
}
```

## Ruby

```bash
gem install vive
```

```ruby
require "vive"

vive = Vive::Client.new # reads VIVE_API_KEY

begin
  message = vive.send_text(to: "15551234567", text: "Your order shipped.",
                           idempotency_key: "order-4417-shipped")
rescue Vive::Error => e
  raise unless e.code == "outside_service_window"

  vive.send_template(to: "15551234567", template_name: "order_shipped",
                     template_params: ["4417"])
end
```

## Interactive and media

Every client covers all ten message types. Buttons, in each language:

```ts
await vive.sendButtons({
  to: "15551234567",
  text: "Did that fix it?",
  buttons: [{ id: "yes", title: "Yes" }, { id: "no", title: "Still broken" }],
});
```

```python
vive.send_buttons(
    to="15551234567",
    text="Did that fix it?",
    buttons=[{"id": "yes", "title": "Yes"}, {"id": "no", "title": "Still broken"}],
)
```

```go
msg, err := client.SendButtons(ctx, vive.SendButtonsInput{
	To:      "15551234567",
	Text:    "Did that fix it?",
	Buttons: []vive.Button{{ID: "yes", Title: "Yes"}, {ID: "no", Title: "Still broken"}},
})
```

```php
$vive->sendButtons('15551234567', 'Did that fix it?', [
    ['id' => 'yes', 'title' => 'Yes'],
    ['id' => 'no', 'title' => 'Still broken'],
]);
```

```ruby
vive.send_buttons(to: "15551234567", text: "Did that fix it?",
                  buttons: [{ id: "yes", title: "Yes" }, { id: "no", title: "Still broken" }])
```

The tapped button's `id` arrives on the `message.received` webhook as **`replyId`**. Route on
that, not the title — see [sending](./sending.md#buttons).

The same pattern applies to `sendMedia`, `sendList`, `sendCTA`, `sendLocation`, and `react`.

## Retry behaviour

Every client retries `429` and `5xx` twice by default, with exponential backoff and jitter,
and **never** retries a `4xx` — those fail identically no matter how many times you try.

Pair retries with an `Idempotency-Key` so a retried send can't duplicate.

## Verifying webhooks

Each SDK exposes `parseWebhook` / `parse_webhook` / `ParseWebhook`, which verifies the
signature and decodes in one call. Pass the **raw request body** — see
[webhooks](./webhooks.md) for why that matters.
