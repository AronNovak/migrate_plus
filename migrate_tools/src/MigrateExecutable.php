<?php

/**
 * @file
 * Contains \Drupal\migrate_tools\MigrateExecutable.
 */

namespace Drupal\migrate_tools;

use Drupal\migrate\MigrateExecutable as MigrateExecutableBase;
use Drupal\migrate\MigrateMessageInterface;
use Drupal\migrate\Entity\MigrationInterface;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateMapSaveEvent;
use Drupal\migrate\Event\MigrateMapDeleteEvent;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;

class MigrateExecutable extends MigrateExecutableBase {

  /**
   * Counters of map statuses.
   *
   * @var array
   *   Set of counters, keyed by MigrateIdMapInterface::STATUS_* constant.
   */
  protected $saveCounters = array(
    MigrateIdMapInterface::STATUS_FAILED => 0,
    MigrateIdMapInterface::STATUS_IGNORED => 0,
    MigrateIdMapInterface::STATUS_IMPORTED => 0,
    MigrateIdMapInterface::STATUS_NEEDS_UPDATE => 0,
  );

  /**
   * Counter of map deletions.
   *
   * @var int
   */
  protected $deleteCounter = 0;

  /**
   * Maximum number of items to process in this migration. 0 indicates no limit
   * is to be applied.
   *
   * @var int
   */
  protected $itemLimit = 0;

  /**
   * Frequency (in items) at which progress messages should be emitted.
   *
   * @var int
   */
  protected $feedback = 0;

  /**
   * Count of number of items processed so far in this migration.
   * @var int
   */
  protected $counter = 0;

  /**
   * {@inheritdoc}
   */
  public function __construct(MigrationInterface $migration, MigrateMessageInterface $message, array $options = []) {
    parent::__construct($migration, $message);
    if (isset($options['feedback'])) {
      $this->feedback = $options['feedback'];
    }
    \Drupal::service('event_dispatcher')->addListener(MigrateEvents::MAP_SAVE,
      array($this, 'onMapSave'));
    \Drupal::service('event_dispatcher')->addListener(MigrateEvents::MAP_DELETE,
      array($this, 'onMapDelete'));
    \Drupal::service('event_dispatcher')->addListener(MigrateEvents::POST_IMPORT,
      array($this, 'onPostImport'));
    \Drupal::service('event_dispatcher')->addListener(MigrateEvents::POST_ROW_SAVE,
      array($this, 'onPostSave'));
  }

  /**
   * Count up any map save events.
   *
   * @param \Drupal\migrate\Event\MigrateMapSaveEvent $event
   *   The map event.
   */
  public function onMapSave(MigrateMapSaveEvent $event) {
    $fields = $event->getFields();
    $this->saveCounters[$fields['source_row_status']]++;
  }

  /**
   * Count up any rollback events.
   *
   * @param \Drupal\migrate\Event\MigrateMapDeleteEvent $event
   *   The map event.
   */
  public function onMapDelete(MigrateMapDeleteEvent $event) {
    $this->deleteCounter++;
  }

  /**
   * Return the number of items imported.
   *
   * @return int
   */
  public function getImportedCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_IMPORTED];
  }

  /**
   * Return the number of items ignored.
   *
   * @return int
   */
  public function getIgnoredCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_IGNORED];
  }

  /**
   * Return the number of items that failed.
   *
   * @return int
   */
  public function getFailedCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_FAILED];
  }

  /**
   * Return the total number of items processed. Note that STATUS_NEEDS_UPDATE
   * is not counted, since this is typically set on stubs created as side
   * effects, not on the primary item being imported.
   *
   * @return int
   */
  public function getProcessedCount() {
    return $this->saveCounters[MigrateIdMapInterface::STATUS_IMPORTED] +
      $this->saveCounters[MigrateIdMapInterface::STATUS_IGNORED] +
      $this->saveCounters[MigrateIdMapInterface::STATUS_FAILED];
  }

  /**
   * Return the number of items rolled back.
   *
   * @return int
   */
  public function getRollbackCount() {
    return $this->deleteCounter;
  }

  /**
   * Reset all the per-status counters to 0.
   */
  protected function resetCounters() {
    foreach ($this->saveCounters as $status => $count) {
      $this->saveCounters[$status] = 0;
    }
    $this->deleteCounter = 0;
  }

  /**
   * React to migration completion.
   *
   * @param \Drupal\migrate\Event\MigrateImportEvent $event
   *   The map event.
   */
  public function onPostImport(MigrateImportEvent $event) {
    $migrate_last_imported_store = \Drupal::keyValue('migrate_last_imported');
    $migrate_last_imported_store->set($event->getMigration()->id(), round(microtime(TRUE) * 1000));
    $this->progressMessage();
  }

  /**
   * Emit information on what we've done since the last feedback (or the
   * beginning of this migration).
   *
   * @param bool $done
   */
  protected function progressMessage($done = TRUE) {
    $processed = $this->getProcessedCount();
    if ($done) {
      $singular_message = "Processed 1 item (!successes successfully, !failures failed, !ignored ignored) - done with '!name'";
      $plural_message = "Processed !numitems items (!successes successfully, !failures failed, !ignored ignored) - done with '!name'";
    }
    else {
      $singular_message = "Processed 1 item (!successes successfully, !failures failed, !ignored ignored) - continuing with '!name'";
      $plural_message = "Processed !numitems items (!successes successfully, !failures failed, !ignored ignored) - continuing with '!name'";
    }
    $this->message->display(\Drupal::translation()->formatPlural($processed,
      $singular_message, $plural_message,
        array('!numitems' => $processed,
              '!successes' => $this->getImportedCount(),
              '!failures' => $this->getFailedCount(),
              '!ignored' => $this->getIgnoredCount(),
              '!name' => $this->migration->id())));
  }

  /**
   * React to item import.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   The post-save event.
   */
  public function onPostSave(MigratePostRowSaveEvent $event) {
    if ($this->feedback && ($this->counter) && $this->counter % $this->feedback == 0) {
      $this->progressMessage(FALSE);
      $this->resetCounters();
    }
    $this->counter++;
  }

}
