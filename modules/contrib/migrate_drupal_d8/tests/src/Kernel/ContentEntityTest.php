<?php

namespace Drupal\Tests\migrate_drupal_d8\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\field\Tests\EntityReference\EntityReferenceTestTrait;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\user\Entity\User;

/**
 * Tests migration destination table.
 *
 * @group migrate
 * @group migrate_drupal_8
 */
class ContentEntityTest extends MigrateTestBase {

  use EntityReferenceTestTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'migrate_drupal_d8',
    'migrate',
    'user',
    'system',
    'node',
    'taxonomy',
    'field',
    'file',
    'image',
    'media',
    'media_test_source',
    'text',
    'filter',
  ];

  /**
   * The bundle used in this test.
   *
   * @var string
   */
  protected $bundle = 'article';

  /**
   * The name of the field used in this test.
   *
   * @var string
   */
  protected $fieldName = 'field_entity_reference';

  /**
   * The vocabulary id.
   *
   * @var string
   */
  protected $vocabulary = 'fruit';

  /**
   * The test user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $user;

  /**
   * The migration plugin.
   *
   * @var \Drupal\migrate\Plugin\MigrationInterface
   */
  protected $migration;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Create article content type.
    $nodeType = NodeType::create(['type' => $this->bundle, 'name' => 'Article']);
    $nodeType->save();

    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('taxonomy_vocabulary');
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', 'users_data');
    $this->installSchema('file', 'file_usage');

    $this->installConfig($this->modules);

    // Create a vocabulary.
    $vocabulary = Vocabulary::create([
      'name' => $this->vocabulary,
      'description' => $this->vocabulary,
      'vid' => $this->vocabulary,
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    ]);
    $vocabulary->save();

    // Create a term reference field on node.
    $this->createEntityReferenceField(
      'node',
      $this->bundle,
      $this->fieldName,
      'Term reference',
      'taxonomy_term',
      'default',
      ['target_bundles' => [$this->vocabulary]],
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED
    );
    // Create a term reference field on user.
    $this->createEntityReferenceField(
      'user',
      'user',
      $this->fieldName,
      'Term reference',
      'taxonomy_term',
      'default',
      ['target_bundles' => [$this->vocabulary]],
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED
    );

    // Create a media type.
    $mediaType = $this->createMediaType('test');

    // Create some data.
    $this->user = User::create([
      'name' => 'user123',
      'uid' => 1,
      'mail' => 'example@example.com',
    ]);
    $this->user->save();

    $term = Term::create([
      'vid' => $this->vocabulary,
      'name' => 'Apples',
      'uid' => $this->user->id(),
    ]);
    $term->save();
    $term2 = Term::create([
      'vid' => $this->vocabulary,
      'name' => 'Granny Smith',
      'uid' => $this->user->id(),
      'parent' => $term->id(),
    ]);
    $term2->save();
    $this->user->set($this->fieldName, $term->id());
    $this->user->save();
    $node = Node::create([
      'type' => $this->bundle,
      'title' => 'Apples',
      $this->fieldName => $term->id(),
      'uid' => $this->user->id(),
    ]);
    $node->save();
    $file = File::create([
      'filename' => 'foo.txt',
      'uid' => $this->user->id(),
      'uri' => 'public://foo.txt',
    ]);
    $file->save();
    $media = Media::create([
      'name' => 'Foo media',
      'uid' => $this->user->id(),
      'bundle' => $mediaType->id(),
    ]);
    $media->save();
  }

  /**
   * Tests table destination.
   */
  public function testEntitySource() {
    /** @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migrationPluginManager */
    $migrationPluginManager = \Drupal::service('plugin.manager.migration');
    $definition = $this->migrationDefinition();

    // User tests.
    $definition['source']['entity_type'] = 'user';
    $source = $migrationPluginManager->createStubMigration($definition)->getSourcePlugin();
    $ids = $source->getIds();
    $this->assertArrayHasKey('langcode', $ids);
    $this->assertArrayHasKey('uid', $ids);
    $fields = $source->fields();
    $this->assertArrayHasKey('name', $fields);
    $this->assertArrayHasKey('pass', $fields);
    $this->assertArrayHasKey('mail', $fields);
    $this->assertArrayHasKey('uid', $fields);
    $this->assertArrayHasKey('roles', $fields);
    $source->rewind();
    $values = $source->current()->getSource();
    $this->assertEquals('example@example.com', $values['mail']);
    $this->assertEquals('user123', $values['name']);
    $this->assertEquals(1, $values['uid']);
    $this->assertEquals(1, $values['field_entity_reference'][0]['target_id']);

    // File testing.
    $definition['source']['entity_type'] = 'file';
    $source = $migrationPluginManager->createStubMigration($definition)->getSourcePlugin();
    $ids = $source->getIds();
    $this->assertArrayHasKey('fid', $ids);
    $fields = $source->fields();
    $this->assertArrayHasKey('fid', $fields);
    $this->assertArrayHasKey('filemime', $fields);
    $this->assertArrayHasKey('filename', $fields);
    $this->assertArrayHasKey('uid', $fields);
    $this->assertArrayHasKey('uri', $fields);
    $source->rewind();
    $values = $source->current()->getSource();
    $this->assertEquals('text/plain', $values['filemime']);
    $this->assertEquals('public://foo.txt', $values['uri']);
    $this->assertEquals('foo.txt', $values['filename']);
    $this->assertEquals(1, $values['fid']);

    // Node tests.
    $definition['source']['entity_type'] = 'node';
    $definition['source']['bundle'] = $this->bundle;
    $source = $migrationPluginManager->createStubMigration($definition)->getSourcePlugin();
    $ids = $source->getIds();
    $this->assertArrayHasKey('langcode', $ids);
    $this->assertArrayHasKey('nid', $ids);
    $fields = $source->fields();
    $this->assertArrayHasKey('nid', $fields);
    $this->assertArrayHasKey('vid', $fields);
    $this->assertArrayHasKey('title', $fields);
    $this->assertArrayHasKey('uid', $fields);
    $this->assertArrayHasKey('sticky', $fields);
    $source->rewind();
    $values = $source->current()->getSource();
    $this->assertEquals($this->bundle, $values['type']);
    $this->assertEquals('node', $values['entity_type']);
    $this->assertEquals(1, $values['nid']);
    $this->assertEquals(1, $values['status']);
    $this->assertEquals('Apples', $values['title']);
    $this->assertEquals(1, $values['field_entity_reference'][0]['target_id']);

    // Media testing.
    $definition['source']['entity_type'] = 'media';
    $definition['source']['bundle'] = 'image';
    $source = $migrationPluginManager->createStubMigration($definition)->getSourcePlugin();
    $ids = $source->getIds();
    $this->assertArrayHasKey('langcode', $ids);
    $this->assertArrayHasKey('mid', $ids);
    $fields = $source->fields();
    $this->assertArrayHasKey('bundle', $fields);
    $this->assertArrayHasKey('mid', $fields);
    $this->assertArrayHasKey('name', $fields);
    $this->assertArrayHasKey('status', $fields);
    $source->rewind();
    $values = $source->current()->getSource();
    $this->assertEquals(1, $values['mid']);
    $this->assertEquals('Foo media', $values['name']);
    $this->assertEquals('Foo media', $values['thumbnail__title']);
    $this->assertEquals(1, $values['uid']);
    $this->assertEquals('image', $values['bundle']);

    // Term testing.
    $definition['source']['plugin'] = 'd8_taxonomy_term';
    $definition['source']['bundle'] = $this->vocabulary;
    $source = $migrationPluginManager->createStubMigration($definition)->getSourcePlugin();
    $ids = $source->getIds();
    $this->assertArrayHasKey('langcode', $ids);
    $this->assertArrayHasKey('tid', $ids);
    $fields = $source->fields();
    $this->assertArrayHasKey('vid', $fields);
    $this->assertArrayHasKey('tid', $fields);
    $this->assertArrayHasKey('name', $fields);
    $source->rewind();
    $values = $source->current()->getSource();
    $this->assertEquals($this->vocabulary, $values['vid']);
    $this->assertEquals(1, $values['tid']);
    $this->assertEquals(0, $values['parent']);
    $this->assertEquals('Apples', $values['name']);
    $source->next();
    $values = $source->current()->getSource();
    $this->assertEquals($this->vocabulary, $values['vid']);
    $this->assertEquals(2, $values['tid']);
    $this->assertEquals(1, $values['parent']);
    $this->assertEquals('Granny Smith', $values['name']);
  }

  /**
   * Get a migration definition.
   *
   * @return array
   *   The definition.
   */
  protected function migrationDefinition() {
    return [
      'source' => [
        'plugin' => 'd8_entity',
        'key' => 'default',
      ],
      'process' => [],
      'destination' => [
        'plugin' => 'null',
      ],
    ];
  }

  /**
   * Create a media type for a source plugin.
   *
   * @param string $media_source_name
   *   The name of the media source.
   *
   * @return \Drupal\media\MediaTypeInterface
   *   A media type.
   */
  protected function createMediaType($media_source_name) {
    $id = strtolower($this->randomMachineName());
    $media_type = MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => $media_source_name,
      'new_revision' => FALSE,
    ]);
    $media_type->save();
    $source_field = $media_type->getSource()->createSourceField($media_type);
    // The media type form creates a source field if it does not exist yet. The
    // same must be done in a kernel test, since it does not use that form.
    // @see \Drupal\media\MediaTypeForm::save()
    $source_field->getFieldStorageDefinition()->save();
    // The source field storage has been created, now the field can be saved.
    $source_field->save();
    $media_type->set('source_configuration', [
      'source_field' => $source_field->getName(),
    ])->save();
    return $media_type;
  }

}
