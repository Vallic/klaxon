<?php

declare(strict_types=1);

namespace Drupal\Tests\klaxon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\klaxon\DeliveryException;
use Drupal\klaxon\Message;
use Drupal\klaxon\Plugin\Klaxon\Transport\DiscordTransport;
use Drupal\klaxon\Plugin\Klaxon\Transport\SlackTransport;
use Drupal\klaxon\Plugin\Klaxon\Transport\TelegramTransport;
use Drupal\klaxon\Plugin\Klaxon\Transport\WebhookTransport;
use Drupal\klaxon\Transport\TransportInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers what the chat transports put on the wire, and how they fail.
 *
 * Nothing here talks to Slack. What is worth testing is the payload each
 * service will accept and, more importantly, which failures are worth retrying
 * — a transport that calls a rate limit permanent drops alerts silently.
 */
#[Group('klaxon')]
#[RunTestsInSeparateProcesses]
class ChatTransportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'klaxon'];

  /**
   * Requests the mocked client was asked to make.
   */
  protected array $history = [];

  /**
   * Slack gets a header, a body, its facts and a severity color.
   */
  public function testSlackPayload(): void {
    $transport = $this->transport(SlackTransport::class, 'slack', ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']);
    $transport->send($this->message());

    $payload = $this->lastPayload();
    $blocks = $payload['attachments'][0]['blocks'];

    $this->assertSame('#d72b3f', $payload['attachments'][0]['color'], 'Critical is red.');
    $this->assertSame('Something broke', $payload['text'], 'A fallback for clients that cannot render blocks.');
    $this->assertSame('header', $blocks[0]['type']);
    $this->assertSame('Something broke', $blocks[0]['text']['text']);
    $this->assertSame('Six of them, at once.', $blocks[1]['text']['text']);
    $this->assertSame("*Server*\nweb-1", $blocks[2]['fields'][0]['text'], 'A real newline, not a literal backslash-n.');
  }

  /**
   * Slack rejects a header over 150 characters, so it never sees one.
   */
  public function testSlackTruncatesTheHeader(): void {
    $transport = $this->transport(SlackTransport::class, 'slack', ['webhook_url' => 'https://hooks.slack.com/services/T/B/x']);
    $transport->send(new Message(str_repeat('a', 400), 'body'));

    $header = $this->lastPayload()['attachments'][0]['blocks'][0]['text']['text'];

    $this->assertSame(150, mb_strlen($header));
    $this->assertStringEndsWith('…', $header);
  }

  /**
   * Telegram gets escaped HTML and the chat it was pointed at.
   */
  public function testTelegramPayload(): void {
    $transport = $this->transport(TelegramTransport::class, 'telegram', [
      'token' => '123:abc',
      'chat_id' => '-100999',
    ], new Response(200, [], '{"ok":true}'));

    $transport->send($this->message());

    $this->assertSame(
      'https://api.telegram.org/bot123:abc/sendMessage',
      (string) $this->history[0]['request']->getUri(),
    );

    $payload = $this->lastPayload();

    $this->assertSame('-100999', $payload['chat_id']);
    $this->assertSame('HTML', $payload['parse_mode']);
    $this->assertStringContainsString('<b>Something broke</b>', $payload['text']);
    $this->assertStringContainsString('<b>Server:</b> web-1', $payload['text']);
    $this->assertStringContainsString('&lt;script&gt;', $payload['text'], 'Angle brackets in a fact cannot break the markup.');
  }

  /**
   * Telegram can refuse with a 200, so the status code is not the whole story.
   */
  public function testTelegramRefusalWithHttpSuccess(): void {
    $transport = $this->transport(TelegramTransport::class, 'telegram', [
      'token' => '123:abc',
      'chat_id' => '-100999',
    ], new Response(200, [], '{"ok":false,"description":"chat not found"}'));

    try {
      $transport->send($this->message());
      $this->fail('A refusal should not count as delivered.');
    }
    catch (DeliveryException $e) {
      $this->assertTrue($e->isPermanent(), 'A chat that does not exist will not start existing.');
      $this->assertStringContainsString('chat not found', $e->getMessage());
    }
  }

  /**
   * Discord gets an embed, with the color as an integer.
   */
  public function testDiscordPayload(): void {
    $transport = $this->transport(DiscordTransport::class, 'discord', [
      'webhook_url' => 'https://discord.com/api/webhooks/1/x',
      'username' => 'Klaxon',
    ], new Response(204));

    $transport->send($this->message());

    $payload = $this->lastPayload();
    $embed = $payload['embeds'][0];

    $this->assertSame('Klaxon', $payload['username']);
    $this->assertSame('Something broke', $embed['title']);
    $this->assertSame(0xd72b3f, $embed['color']);
    $this->assertSame('Server', $embed['fields'][0]['name']);
    $this->assertSame('web-1', $embed['fields'][0]['value']);
  }

  /**
   * Discord throws out an embed field with an empty value, so none are sent.
   */
  public function testDiscordNeverSendsAnEmptyField(): void {
    $transport = $this->transport(DiscordTransport::class, 'discord', [
      'webhook_url' => 'https://discord.com/api/webhooks/1/x',
    ], new Response(204));

    $transport->send(new Message('Subject', 'Body', ['Empty' => '  ']));

    $this->assertSame('—', $this->lastPayload()['embeds'][0]['fields'][0]['value']);
  }

  /**
   * The webhook sends the documented shape, with the configured headers.
   */
  public function testWebhookPayloadAndHeaders(): void {
    $transport = $this->transport(WebhookTransport::class, 'webhook', [
      'url' => 'https://ops.example.com/hook',
      'method' => 'PUT',
      'headers' => "Authorization: Bearer abc123\n\nX-Api-Key: xyz",
    ], new Response(202));

    $transport->send($this->message());

    $request = $this->history[0]['request'];

    $this->assertSame('PUT', $request->getMethod(), 'The configured method is used.');
    $this->assertSame('Bearer abc123', $request->getHeaderLine('Authorization'));
    $this->assertSame('xyz', $request->getHeaderLine('X-Api-Key'), 'A blank line between headers is not one.');
    $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

    $payload = $this->lastPayload();

    $this->assertSame('Something broke', $payload['subject']);
    $this->assertSame('critical', $payload['severity']);
    $this->assertSame(['Server' => 'web-1', 'Detail' => '<script>'], $payload['facts']);
    $this->assertNull($payload['url']);
    $this->assertNotEmpty($payload['sent']);
  }

  /**
   * With no facts, the receiver still gets an object rather than an array.
   */
  public function testWebhookFactsAreAlwaysAnObject(): void {
    $transport = $this->transport(WebhookTransport::class, 'webhook', [
      'url' => 'https://ops.example.com/hook',
    ], new Response(202));

    $transport->send(new Message('Subject', 'Body'));

    $this->assertStringContainsString(
      '"facts":{}',
      (string) $this->history[0]['request']->getBody(),
      'An empty PHP array would encode as [] and break a typed receiver.',
    );
  }

  /**
   * The signature covers the exact bytes sent, alongside the timestamp.
   */
  public function testWebhookSignsWhatItSends(): void {
    $transport = $this->transport(WebhookTransport::class, 'webhook', [
      'url' => 'https://ops.example.com/hook',
      'secret' => 'a-signing-secret',
    ], new Response(202));

    $transport->send($this->message());

    $request = $this->history[0]['request'];
    $timestamp = $request->getHeaderLine(WebhookTransport::TIMESTAMP_HEADER);
    $signature = $request->getHeaderLine(WebhookTransport::SIGNATURE_HEADER);

    $this->assertNotSame('', $timestamp);
    $this->assertSame(
      hash_hmac('sha256', $timestamp . '.' . (string) $request->getBody(), 'a-signing-secret'),
      $signature,
      'A receiver recomputing this over the body it got must agree.',
    );
  }

  /**
   * Nothing is signed when no secret was given.
   */
  public function testWebhookWithNoSecretIsNotSigned(): void {
    $transport = $this->transport(WebhookTransport::class, 'webhook', [
      'url' => 'https://ops.example.com/hook',
    ], new Response(202));

    $transport->send($this->message());

    $this->assertFalse($this->history[0]['request']->hasHeader(WebhookTransport::SIGNATURE_HEADER));
  }

  /**
   * A rate limit is never permanent, and carries the wait that was asked for.
   */
  public function testRateLimitsAreRetriedAfterTheStatedWait(): void {
    $cases = [
      'slack header' => [
        SlackTransport::class,
        'slack',
        ['webhook_url' => 'https://hooks.slack.com/services/T/B/x'],
        new Response(429, ['Retry-After' => '30'], 'rate_limited'),
        30,
      ],
      'telegram body' => [
        TelegramTransport::class,
        'telegram',
        ['token' => '123:abc', 'chat_id' => '-1'],
        new Response(429, [], '{"ok":false,"parameters":{"retry_after":17}}'),
        17,
      ],
      'discord fractional body' => [
        DiscordTransport::class,
        'discord',
        ['webhook_url' => 'https://discord.com/api/webhooks/1/x'],
        new Response(429, [], '{"retry_after":2.5}'),
        3,
      ],
    ];

    foreach ($cases as $name => [$class, $id, $config, $response, $expected]) {
      $transport = $this->transport($class, $id, $config, $response);

      try {
        $transport->send($this->message());
        $this->fail(sprintf('%s: a rate limit should have been raised.', $name));
      }
      catch (DeliveryException $e) {
        $this->assertFalse($e->isPermanent(), $name . ': waiting fixes a rate limit.');
        $this->assertSame($expected, $e->getRetryAfter(), $name . ': the service said how long.');
      }
    }
  }

  /**
   * A refusal is permanent; an outage or a dead connection is not.
   */
  public function testWhichFailuresAreWorthRetrying(): void {
    $url = ['webhook_url' => 'https://hooks.slack.com/services/T/B/x'];

    $refused = $this->transport(SlackTransport::class, 'slack', $url, new Response(404, [], 'no_service'));
    $this->assertTrue($this->caught($refused)->isPermanent(), 'A deleted webhook stays deleted.');

    $broken = $this->transport(SlackTransport::class, 'slack', $url, new Response(500, [], 'oops'));
    $this->assertFalse($this->caught($broken)->isPermanent(), 'Their bad afternoon is not our permanent failure.');

    $timeout = $this->transport(SlackTransport::class, 'slack', $url, new Response(408));
    $this->assertFalse($this->caught($timeout)->isPermanent(), 'A timeout is worth another go.');

    $unreachable = $this->transport(
      SlackTransport::class,
      'slack',
      $url,
      new ConnectException('Connection refused', new Request('POST', 'https://hooks.slack.com')),
    );
    $this->assertFalse($this->caught($unreachable)->isPermanent(), 'A dropped connection is worth another go.');
  }

  /**
   * A channel with no credentials fails permanently rather than calling out.
   */
  public function testMissingCredentialsFailWithoutAnyRequest(): void {
    foreach ([
      [SlackTransport::class, 'slack', []],
      [TelegramTransport::class, 'telegram', ['token' => '123:abc']],
      [DiscordTransport::class, 'discord', []],
      [WebhookTransport::class, 'webhook', []],
    ] as [$class, $id, $config]) {
      $transport = $this->transport($class, $id, $config);

      $this->assertTrue($this->caught($transport)->isPermanent(), $id . ': nothing to retry against.');
      $this->assertSame([], $this->history, $id . ': and no request was made.');
    }
  }

  /**
   * Builds one transport over a mocked HTTP client.
   */
  protected function transport(string $class, string $id, array $configuration, Response|ConnectException|null $response = NULL): TransportInterface {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler([$response ?? new Response(200, [], 'ok')]));
    $stack->push(Middleware::history($this->history));

    $client = new Client(['handler' => $stack]);
    $definition = ['label' => ucfirst($id)];

    // The webhook signs its body, so it needs to agree with the queue about
    // what time it is.
    if ($class === WebhookTransport::class) {
      return new $class($configuration, $id, $definition, $client, $this->container->get('datetime.time'));
    }

    return new $class($configuration, $id, $definition, $client);
  }

  /**
   * The alert every payload test sends.
   */
  protected function message(): Message {
    return new Message(
      'Something broke',
      'Six of them, at once.',
      ['Server' => 'web-1', 'Detail' => '<script>'],
      Message::SEVERITY_CRITICAL,
    );
  }

  /**
   * The body of the request the transport actually made.
   */
  protected function lastPayload(): array {
    $this->assertNotEmpty($this->history, 'The transport made a request.');

    return json_decode((string) $this->history[0]['request']->getBody(), TRUE);
  }

  /**
   * Sends and returns the failure, failing the test if there was not one.
   */
  protected function caught(TransportInterface $transport): DeliveryException {
    try {
      $transport->send($this->message());
    }
    catch (DeliveryException $e) {
      return $e;
    }

    $this->fail('Expected the delivery to fail.');
  }

}
