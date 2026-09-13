<?php

declare(strict_types=1);

namespace Drupal\klaxon\Plugin\Klaxon\Transport;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\klaxon\Attribute\Transport;
use Drupal\klaxon\Message;
use Drupal\klaxon\Transport\TransportBase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Writes the alert to the log.
 *
 * Always available, needs no credentials, and is the right channel to add to
 * every alert while you are still deciding whether it fires too often.
 */
#[Transport(
  id: 'log',
  label: new TranslatableMarkup('Log'),
  description: new TranslatableMarkup('Record the alert in the site log. Useful for testing and as an audit trail.'),
  category: new TranslatableMarkup('General'),
)]
class LogTransport extends TransportBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('logger.channel.klaxon'));
  }

  /**
   * {@inheritdoc}
   */
  public function send(Message $message): void {
    $this->logger->log($this->level($message->severity), '@subject @body', [
      '@subject' => $message->subject,
      '@body' => $message->flatten(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    return (string) new TranslatableMarkup('Site log');
  }

  /**
   * The log level matching a message severity.
   */
  protected function level(string $severity): string {
    return match ($severity) {
      Message::SEVERITY_CRITICAL => LogLevel::CRITICAL,
      Message::SEVERITY_WARNING => LogLevel::WARNING,
      default => LogLevel::INFO,
    };
  }

}
