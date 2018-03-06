<?php

namespace Drupal\migrate_destination_csv\Plugin\migrate\destination;

use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;

/**
 * Outputs migration as CSV file.
 *
 * Available configuration keys:
 * - path: Path for output file.
 *
 * @MigrateDestination(
 *   id = "csv"
 * )
 *
 * @package Drupal\migrate_destination_csv\Plugin\migrate\destination
 */
class Csv extends DestinationBase {

  protected $supportsRollback = TRUE;

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['value']['type'] = 'string';
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
  }

  /**
   * Import the row.
   *
   * Derived classes must implement import(), to construct one new object
   * (pre-populated) using ID mappings in the Migration.
   *
   * @param \Drupal\migrate\Row $row
   *   The row object.
   * @param array $old_destination_id_values
   *   (optional) The old destination IDs. Defaults to an empty array.
   *
   * @return mixed
   *   The entity ID or an indication of success.
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $path = $this->migration->getDestinationPlugin()->configuration['path'];

    if (!file_exists($path)) {
      // File doesn't exist.
      // Create it and add column headers.
      $keys = array_keys($row->getDestination());
      $csv = implode(',', $keys);
      $csv .= PHP_EOL;

      file_put_contents($path, $csv, FILE_APPEND);
    }

    $csv_row = [];
    foreach ($row->getDestination() as $field => $value) {
      $csv_row[] = $value;
    }
    $csv = implode(',', $csv_row);
    $csv .= PHP_EOL;

    file_put_contents($path, $csv, FILE_APPEND);

    return ['csv' => $row->getSource()['uuid']];
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $path = $this->migration->getDestinationPlugin()->configuration['path'];
    if (file_exists($path)) {
      unlink($path);
    }
    parent::rollback($destination_identifier);
  }

}
