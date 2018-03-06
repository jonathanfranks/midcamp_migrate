<?php

namespace Drupal\migrate_drupal_d8\Plugin\migrate\source\d8;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;

/**
 * Drupal 8 taxonomy_term source from database.
 *
 * @MigrateSource(
 *   id = "d8_taxonomy_term",
 *   source_provider = "migrate_drupal_d8"
 * )
 */
class TaxonomyTerm extends ContentEntity {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, StateInterface $state, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $configuration['entity_type'] = 'taxonomy_term';
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state, $entity_type_manager, $entity_field_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $tid = $row->getSourceProperty('tid');
    $parent = $this->taxonomyTermGetParent($tid);
    if ($parent !== FALSE) {
      $row->setSourceProperty('parent', $parent);
    }
    return parent::prepareRow($row);
  }

  /**
   * The parent value has custom storage, retrieve it directly.
   *
   * @param int $tid
   *   The term id.
   *
   * @return bool|int
   *   The parent term id or FALSE if there is none.
   */
  protected function taxonomyTermGetParent($tid) {
    /** @var \Drupal\Core\Database\Query\SelectInterface $query */
    $query = $this->select('taxonomy_term_hierarchy', 'h')
      ->fields('h', ['parent'])
      ->condition('tid', $tid);
    return $query->execute()->fetchField();
  }

}
