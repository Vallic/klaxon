<?php

declare(strict_types=1);

namespace Drupal\klaxon\Transport;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\klaxon\DeliveryException;
use Drupal\klaxon\Message;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for transports that post JSON at a chat service.
 *
 * Slack, Telegram and Discord differ only in the shape of the payload and
 * where they hide the rate limit. Everything else — the timeouts that keep a
 * slow chat API from stalling cron, and deciding which failures are worth
 * retrying — is the same for all of them and lives here.
 *
 * None of them needs a library. That is why they are in the module rather than
 * in a submodule each: there is no dependency to make opt-in.
 */
abstract class HttpTransportBase extends TransportBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Seconds to wait for a connection, then for the whole request.
   *
   * Deliberately short. Delivery happens on cron, behind a queue that will
   * retry, so waiting is worth less than getting on with the run.
   */
  protected const CONNECT_TIMEOUT = 5;
  protected const TIMEOUT = 10;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly ClientInterface $httpClient,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('http_client'));
  }

  /**
   * Encodes a payload as JSON and posts it.
   *
   * @param string $url
   *   Where to post.
   * @param array $payload
   *   The body, encoded as JSON.
   * @param array $headers
   *   Extra headers, such as an authorization token.
   *
   * @return array
   *   The decoded response body, or an empty array when there was none.
   *
   * @throws \Drupal\klaxon\DeliveryException
   */
  protected function post(string $url, array $payload, array $headers = []): array {
    return $this->request('POST', $url, static::encode($payload), $headers);
  }

  /**
   * Encodes a payload the one way every transport here encodes it.
   *
   * Exposed so a transport that signs its request can sign exactly the bytes
   * that will be sent, rather than a second encoding that might differ.
   */
  protected static function encode(array $payload): string {
    return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Sends an already-encoded body and returns the decoded response.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $url
   *   Where to send it.
   * @param string $body
   *   The encoded request body.
   * @param array $headers
   *   Extra headers, such as an authorization token.
   *
   * @return array
   *   The decoded response body, or an empty array when there was none.
   *
   * @throws \Drupal\klaxon\DeliveryException
   */
  protected function request(string $method, string $url, string $body, array $headers = []): array {
    try {
      $response = $this->httpClient->request($method, $url, [
        'body' => $body,
        'headers' => ['Content-Type' => 'application/json'] + $headers,
        'connect_timeout' => static::CONNECT_TIMEOUT,
        'timeout' => static::TIMEOUT,
      ]);
    }
    catch (RequestException $e) {
      $response = $e->getResponse();

      if ($response === NULL) {
        // No reply at all: DNS, TLS, a dropped connection. Worth retrying.
        throw new DeliveryException($e->getMessage(), FALSE, NULL, $e);
      }

      throw $this->failure(
        $response->getStatusCode(),
        (string) $response->getBody(),
        $response->getHeaderLine('Retry-After') ?: NULL,
      );
    }
    catch (ConnectException $e) {
      throw new DeliveryException($e->getMessage(), FALSE, NULL, $e);
    }
    catch (TransferException $e) {
      throw new DeliveryException($e->getMessage(), FALSE, NULL, $e);
    }

    $body = (string) $response->getBody();

    if ($body === '') {
      return [];
    }

    $decoded = json_decode($body, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Turns an HTTP failure into a decision about retrying.
   *
   * Subclasses override this where the service hides its rate limit in the
   * body rather than in a header, which two of the three do.
   *
   * @param int $status
   *   The HTTP status code.
   * @param string $body
   *   The raw response body.
   * @param string|null $retry_after
   *   The Retry-After header, if the service sent one.
   */
  protected function failure(int $status, string $body, ?string $retry_after): DeliveryException {
    $summary = sprintf('%s returned %d: %s', $this->serviceName(), $status, $this->trim($body, 200));

    if ($status === 429) {
      return DeliveryException::rateLimited($summary, $this->seconds($retry_after) ?? 60);
    }

    // A request the far end refused on its merits will be refused again in ten
    // minutes. A timeout or an overloaded server will not.
    if ($status >= 400 && $status < 500 && !in_array($status, [408, 425], TRUE)) {
      return DeliveryException::permanent($summary);
    }

    return new DeliveryException($summary);
  }

  /**
   * A Retry-After value in seconds, whether given as seconds or as a date.
   */
  protected function seconds(?string $retry_after): ?int {
    if ($retry_after === NULL || $retry_after === '') {
      return NULL;
    }

    if (ctype_digit(trim($retry_after))) {
      return max(1, (int) $retry_after);
    }

    $timestamp = strtotime($retry_after);

    return $timestamp === FALSE ? NULL : max(1, $timestamp - time());
  }

  /**
   * Cuts a string to a length the service will accept.
   */
  protected function trim(string $text, int $limit): string {
    if (mb_strlen($text) <= $limit) {
      return $text;
    }

    return mb_substr($text, 0, max(1, $limit - 1)) . '…';
  }

  /**
   * The severity colour, as six hex digits without a leading hash.
   */
  protected function colour(string $severity): string {
    return match ($severity) {
      Message::SEVERITY_CRITICAL => 'd72b3f',
      Message::SEVERITY_WARNING => 'e5a000',
      Message::SEVERITY_RECOVERED => '2eb886',
      default => '2b7bd7',
    };
  }

  /**
   * The service's name, for error messages.
   */
  protected function serviceName(): string {
    return (string) ($this->pluginDefinition['label'] ?? $this->getPluginId());
  }

}
