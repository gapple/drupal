<?php

namespace Drupal\Tests\layout_builder\Kernel;

use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionListInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageDefinition;

/**
 * @coversDefaultClass \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage
 *
 * @group layout_builder
 */
class OverridesSectionStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'field',
    'system',
    'user',
  ];

  /**
   * The plugin.
   *
   * @var \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installSchema('system', ['key_value_expire']);
    $this->installEntitySchema('entity_test');

    $this->plugin = OverridesSectionStorage::create($this->container, [], 'overrides', new SectionStorageDefinition());
  }

  /**
   * @covers ::access
   * @dataProvider providerTestAccess
   *
   * @param bool $expected
   *   The expected outcome of ::access().
   * @param string $operation
   *   The operation to pass to ::access().
   * @param bool $is_enabled
   *   Whether Layout Builder is enabled for this display.
   * @param array $section_data
   *   Data to store as the sections value for Layout Builder.
   */
  public function testAccess($expected, $operation, $is_enabled, array $section_data) {
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    if ($is_enabled) {
      $display->enableLayoutBuilder();
    }
    $display
      ->setOverridable()
      ->save();

    $entity = EntityTest::create([OverridesSectionStorage::FIELD_NAME => $section_data]);
    $entity->save();

    $this->plugin->setContext('entity', EntityContext::fromEntity($entity));
    $result = $this->plugin->access($operation);
    $this->assertSame($expected, $result);
  }

  /**
   * Provides test data for ::testAccess().
   */
  public function providerTestAccess() {
    $section_data = [
      new Section('layout_default', [], [
        'first-uuid' => new SectionComponent('first-uuid', 'content', ['id' => 'foo']),
      ]),
    ];

    // Data provider values are:
    // - the expected outcome of the call to ::access()
    // - the operation
    // - whether Layout Builder has been enabled for this display
    // - whether this display has any section data.
    $data = [];
    $data['view, disabled, no data'] = [FALSE, 'view', FALSE, []];
    $data['view, enabled, no data'] = [TRUE, 'view', TRUE, []];
    $data['view, disabled, data'] = [FALSE, 'view', FALSE, $section_data];
    $data['view, enabled, data'] = [TRUE, 'view', TRUE, $section_data];
    return $data;
  }

  /**
   * @covers ::getContexts
   */
  public function testGetContexts() {
    $entity = EntityTest::create();
    $entity->save();

    $context = EntityContext::fromEntity($entity);
    $this->plugin->setContext('entity', $context);

    $expected = ['entity' => $context];
    $this->assertSame($expected, $this->plugin->getContexts());
  }

  /**
   * @covers ::getContextsDuringPreview
   */
  public function testGetContextsDuringPreview() {
    $entity = EntityTest::create();
    $entity->save();

    $context = EntityContext::fromEntity($entity);
    $this->plugin->setContext('entity', $context);

    $expected = ['layout_builder.entity' => $context];
    $this->assertSame($expected, $this->plugin->getContextsDuringPreview());
  }

  /**
   * @covers ::setSectionList
   *
   * @expectedDeprecation \Drupal\layout_builder\SectionStorageInterface::setSectionList() is deprecated in Drupal 8.7.0 and will be removed before Drupal 9.0.0. This method should no longer be used. The section list should be derived from context. See https://www.drupal.org/node/3016262.
   * @group legacy
   */
  public function testSetSectionList() {
    $section_list = $this->prophesize(SectionListInterface::class);
    $this->setExpectedException(\Exception::class, '\Drupal\layout_builder\SectionStorageInterface::setSectionList() must no longer be called. The section list should be derived from context. See https://www.drupal.org/node/3016262.');
    $this->plugin->setSectionList($section_list->reveal());
  }

}
