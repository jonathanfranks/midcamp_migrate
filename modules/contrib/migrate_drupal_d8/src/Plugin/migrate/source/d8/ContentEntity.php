<?php

namespace Drupal\migrate_drupal_d8\Plugin\migrate\source\d8;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for D8 source plugins to collect field values from Field API.
 *
 * @MigrateSource(
 *   id = "d8_entity",
 *   source_provider = "migrate_drupal_d8"
 * )
 */
class ContentEntity extends SqlBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Static cache for bundle fields.
   *
   * @var array
   */
  protected $bundleFields = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, StateInterface $state, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    if (empty($configuration['entity_type'])) {
      throw new InvalidPluginDefinitionException($plugin_id, 'Missing required entity_type definition');
    }
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    parent::__construct($configuration + ['bundle' => NULL], $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('state'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * Returns all non-deleted field instances attached to a specific entity type.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string|null $bundle
   *   (optional) The bundle.
   *
   * @todo
   *   I am not sure that we even need that. Would we be doing it wrong if
   *   we relied only on table names?
   *
   * @return array[]
   *   The field instances, keyed by field name.
   */
  protected function getFields($entity_type, $bundle = NULL) {
    $fieldConfig = $this->select('config', 'c')
      ->fields('c')
      ->condition('name', 'field.field.' . $entity_type . '.%', 'LIKE')
      ->execute()
      ->fetchAllAssoc('name');

    $fields = [];
    foreach ($fieldConfig as $config) {
      $data = unserialize($config['data']);
      // Status of false signifies the field is deleted. We do not return
      // deleted data.
      if ($data['status']) {
        // If requested by configuration, filter by a bundle. Don't filter
        // if it isn't configured.
        if ($bundle && $data['bundle'] == $bundle) {
          $fields[$data['field_name']] = $data;
        }
        else {
          $fields[$data['field_name']] = $data;
        }
      }
    }

    return $fields;
  }

  /**
   * Retrieves field values for a single field of a single entity.
   *
   * @param string $entity_type
   *   Entity type.
   * @param string $field_name
   *   The field name.
   * @param int $entity_id
   *   The entity ID.
   * @param int|null $revision_id
   *   (optional) The entity revision ID.
   *
   * @throws \Drupal\migrate\MigrateException
   *
   * @return array
   *   The raw field values, keyed by delta.
   *
   * @todo Support multilingual field values.
   */
  protected function getFieldValues($entity_type, $field_name, $entity_id, $revision_id = NULL) {
    $table = $this->getDedicatedDataTableName($entity_type, $field_name);

    $query = $this->select($table, 't')
      ->fields('t')
      ->condition('entity_id', $entity_id)
      ->condition('deleted', 0);

    if ($revision_id) {
      $query->condition('revision_id', $revision_id);
    }

    $values = [];
    foreach ($query->execute() as $row) {
      foreach ($row as $key => $value) {
        $delta = $row['delta'];
        if (strpos($key, $field_name) === 0) {
          $column = substr($key, strlen($field_name) + 1);
          $values[$delta][$column] = $value;
        }
      }
    }
    return $values;
  }

  /**
   * Get the table name keeping in mind the hashing logic for long names.
   *
   * @param string $entityType
   *   Entity type id.
   * @param string $field_name
   *   Field name.
   * @param bool $revision
   *   If revision table or not.
   *
   * @see \Drupal\Core\Entity\Sql\DefaultTableMapping::generateFieldTableName
   *
   * @throws \Drupal\migrate\MigrateException
   *
   * @return string
   *   The table name string.
   */
  protected function getDedicatedDataTableName($entityType, $field_name, $revision = FALSE) {
    $separator = $revision ? '_revision__' : '__';
    $tableName = $entityType . $separator . $field_name;

    // This matches \Drupal\Core\Entity\Sql\DefaultTableMapping where longer
    // table revision names get shortened by the Entity API.
    if (strlen($tableName) > 48) {
      $separator = $revision ? '_r__' : '__';

      $query = $this->select('config', 'c')
        ->fields('c', ['data'])
        ->condition('name', "field.storage.{$entityType}.{$field_name}");
      $fieldDefinitionData = $query->execute()->fetchField();

      if ($fieldDefinitionData) {
        $data = unserialize($fieldDefinitionData);
        $uuid = $data['uuid'];
      }
      else {
        throw new MigrateException(sprintf('Missing field storage config for field %s', $field_name));
      }

      $entityType = substr($entityType, 0, 34);
      $fieldHash = substr(hash('sha256', $uuid), 0, 10);
      $tableName = $entityType . $separator . $fieldHash;
    }
    return $tableName;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $entityDefinition = $this->entityTypeManager->getDefinition($this->configuration['entity_type']);
    $idKey = $entityDefinition->getKey('id');
    $bundleKey = $entityDefinition->getKey('bundle');
    $baseTable = $entityDefinition->getBaseTable();
    $dataTable = $entityDefinition->getDataTable();

    if ($dataTable) {
      $query = $this->select($dataTable, 'd')
        ->fields('d');
      $alias = $query->innerJoin($baseTable, 'b', "b.{$idKey} = d.{$idKey}");
      $query->fields($alias);
      if (!empty($this->configuration['bundle'])) {
        $query->condition("d.{$bundleKey}", $this->configuration['bundle']);
      }
    }
    else {
      $query = $this->select($baseTable, 'b')
        ->fields('b');
      if (!empty($this->configuration['bundle'])) {
        $query->condition("b.{$bundleKey}", $this->configuration['bundle']);
      }
    }

    return $query;

  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fieldDefinitions = $this->entityFieldManager->getBaseFieldDefinitions($this->configuration['entity_type']);
    $fields = [];
    foreach ($fieldDefinitions as $fieldName => $definition) {
      $fields[$fieldName] = (string) $definition->getLabel();
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $entityType = $this->configuration['entity_type'];
    // Get Field API field values.
    if (!$this->bundleFields) {
      $this->bundleFields = $this->getFields($entityType, $this->configuration['bundle']);
    }

    $entityDefinition = $this->entityTypeManager->getDefinition($this->configuration['entity_type']);
    $idKey = $entityDefinition->getKey('id');
    foreach (array_keys($this->bundleFields) as $field) {
      $entityId = $row->getSourceProperty($idKey);
      $revisionId = NULL;
      if ($entityDefinition->isRevisionable()) {
        $revisionKey = $entityDefinition->getKey('revision');
        $revisionId = $row->getSourceProperty($revisionKey);
      }
      $row->setSourceProperty($field, $this->getFieldValues($entityType, $field, $entityId, $revisionId));
    }

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $entityDefinition = $this->entityTypeManager->getDefinition($this->configuration['entity_type']);
    $idKey = $entityDefinition->getKey('id');
    $ids[$idKey] = $this->getDefinitionFromEntity($idKey);

    if ($entityDefinition->isTranslatable()) {
      $langcodeKey = $entityDefinition->getKey('langcode');
      $ids[$langcodeKey] = $this->getDefinitionFromEntity($langcodeKey);
    }

    return $ids;
  }

  /**
   * Gets the field definition from a specific entity base field.
   *
   * @param string $key
   *   The field ID key.
   *
   * @return array
   *   An associative array with a structure that contains the field type, keyed
   *   as 'type', together with field storage settings as they are returned by
   *   FieldStorageDefinitionInterface::getSettings().
   *
   * @see \Drupal\migrate\Plugin\migrate\destination\EntityContentBase::getDefinitionFromEntity()
   */
  protected function getDefinitionFromEntity($key) {
    /** @var \Drupal\Core\Field\FieldStorageDefinitionInterface[] $fieldDefinitions */
    $fieldDefinitions = $this->entityFieldManager->getBaseFieldDefinitions($this->configuration['entity_type']);
    $fieldDefinition = $fieldDefinitions[$key];

    return [
      'alias' => 'b',
      'type' => $fieldDefinition->getType(),
    ] + $fieldDefinition->getSettings();
  }

}
