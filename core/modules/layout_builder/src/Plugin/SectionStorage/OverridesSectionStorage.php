<?php

namespace Drupal\layout_builder\Plugin\SectionStorage;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Defines the 'overrides' section storage type.
 *
 * @SectionStorage(
 *   id = "overrides",
 *   context_definitions = {
 *     "entity" = @ContextDefinition("entity"),
 *     "view_mode" = @ContextDefinition("string", required = FALSE),
 *   }
 * )
 *
 * @internal
 *   Layout Builder is currently experimental and should only be leveraged by
 *   experimental modules and development releases of contributed modules.
 *   See https://www.drupal.org/core/experimental for more information.
 */
class OverridesSectionStorage extends SectionStorageBase implements ContainerFactoryPluginInterface, OverridesSectionStorageInterface, SectionStorageLocalTaskProviderInterface {

  /**
   * The field name used by this storage.
   *
   * @var string
   */
  const FIELD_NAME = 'layout_builder__layout';

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
   * {@inheritdoc}
   *
   * @var \Drupal\layout_builder\SectionListInterface|\Drupal\Core\Field\FieldItemListInterface
   */
  protected $sectionList;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getSectionList() {
    return $this->getEntity()->get(static::FIELD_NAME);
  }

  /**
   * Gets the entity storing the overrides.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The entity storing the overrides.
   */
  protected function getEntity() {
    return $this->getContextValue('entity');
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageId() {
    $entity = $this->getEntity();
    return $entity->getEntityTypeId() . '.' . $entity->id();
  }

  /**
   * {@inheritdoc}
   */
  public function extractIdFromRoute($value, $definition, $name, array $defaults) {
    @trigger_error('\Drupal\layout_builder\SectionStorageInterface::extractIdFromRoute() is deprecated in Drupal 8.7.0 and will be removed before Drupal 9.0.0. \Drupal\layout_builder\SectionStorageInterface::deriveContextsFromRoute() should be used instead. See https://www.drupal.org/node/3016262.', E_USER_DEPRECATED);
    if (strpos($value, '.') !== FALSE) {
      return $value;
    }

    if (isset($defaults['entity_type_id']) && !empty($defaults[$defaults['entity_type_id']])) {
      $entity_type_id = $defaults['entity_type_id'];
      $entity_id = $defaults[$entity_type_id];
      return $entity_type_id . '.' . $entity_id;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSectionListFromId($id) {
    @trigger_error('\Drupal\layout_builder\SectionStorageInterface::getSectionListFromId() is deprecated in Drupal 8.7.0 and will be removed before Drupal 9.0.0. The section list should be derived from context. See https://www.drupal.org/node/3016262.', E_USER_DEPRECATED);
    if (strpos($id, '.') !== FALSE) {
      list($entity_type_id, $entity_id) = explode('.', $id, 2);
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
      if ($entity instanceof FieldableEntityInterface && $entity->hasField(static::FIELD_NAME)) {
        return $entity->get(static::FIELD_NAME);
      }
    }
    throw new \InvalidArgumentException(sprintf('The "%s" ID for the "%s" section storage type is invalid', $id, $this->getStorageType()));
  }

  /**
   * {@inheritdoc}
   */
  public function deriveContextsFromRoute($value, $definition, $name, array $defaults) {
    $contexts = [];

    if ($entity = $this->extractEntityFromRoute($value, $defaults)) {
      $contexts['entity'] = EntityContext::fromEntity($entity);
    }
    return $contexts;
  }

  /**
   * Extracts an entity from the route values.
   *
   * @param mixed $value
   *   The raw value from the route.
   * @param array $defaults
   *   The route defaults array.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity for the route, or NULL if none exist.
   *
   * @see \Drupal\layout_builder\SectionStorageInterface::deriveContextsFromRoute()
   * @see \Drupal\Core\ParamConverter\ParamConverterInterface::convert()
   */
  private function extractEntityFromRoute($value, array $defaults) {
    if (strpos($value, '.') !== FALSE) {
      list($entity_type_id, $entity_id) = explode('.', $value, 2);
    }
    elseif (isset($defaults['entity_type_id']) && !empty($defaults[$defaults['entity_type_id']])) {
      $entity_type_id = $defaults['entity_type_id'];
      $entity_id = $defaults[$entity_type_id];
    }
    else {
      return NULL;
    }

    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
    if ($entity instanceof FieldableEntityInterface && $entity->hasField(static::FIELD_NAME)) {
      return $entity;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildRoutes(RouteCollection $collection) {
    foreach ($this->getEntityTypes() as $entity_type_id => $entity_type) {
      $defaults = [];
      $defaults['entity_type_id'] = $entity_type_id;

      $requirements = [];
      if ($this->hasIntegerId($entity_type)) {
        $requirements[$entity_type_id] = '\d+';
      }

      $options = [];
      // Ensure that upcasting is run in the correct order.
      $options['parameters']['section_storage'] = [];
      $options['parameters'][$entity_type_id]['type'] = 'entity:' . $entity_type_id;

      $template = $entity_type->getLinkTemplate('canonical') . '/layout';
      $this->buildLayoutRoutes($collection, $this->getPluginDefinition(), $template, $defaults, $requirements, $options, $entity_type_id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildLocalTasks($base_plugin_definition) {
    $local_tasks = [];
    foreach ($this->getEntityTypes() as $entity_type_id => $entity_type) {
      $local_tasks["layout_builder.overrides.$entity_type_id.view"] = $base_plugin_definition + [
        'route_name' => "layout_builder.overrides.$entity_type_id.view",
        'weight' => 15,
        'title' => $this->t('Layout'),
        'base_route' => "entity.$entity_type_id.canonical",
        'cache_contexts' => ['layout_builder_is_active:' . $entity_type_id],
      ];
      $local_tasks["layout_builder.overrides.$entity_type_id.save"] = $base_plugin_definition + [
        'route_name' => "layout_builder.overrides.$entity_type_id.save",
        'title' => $this->t('Save Layout'),
        'parent_id' => "layout_builder_ui:layout_builder.overrides.$entity_type_id.view",
        'cache_contexts' => ['layout_builder_is_active:' . $entity_type_id],
      ];
      $local_tasks["layout_builder.overrides.$entity_type_id.cancel"] = $base_plugin_definition + [
        'route_name' => "layout_builder.overrides.$entity_type_id.cancel",
        'title' => $this->t('Cancel Layout'),
        'parent_id' => "layout_builder_ui:layout_builder.overrides.$entity_type_id.view",
        'weight' => 5,
        'cache_contexts' => ['layout_builder_is_active:' . $entity_type_id],
      ];
      // @todo This link should be conditionally displayed, see
      //   https://www.drupal.org/node/2917777.
      $local_tasks["layout_builder.overrides.$entity_type_id.revert"] = $base_plugin_definition + [
        'route_name' => "layout_builder.overrides.$entity_type_id.revert",
        'title' => $this->t('Revert to defaults'),
        'parent_id' => "layout_builder_ui:layout_builder.overrides.$entity_type_id.view",
        'weight' => 10,
        'cache_contexts' => ['layout_builder_is_active:' . $entity_type_id],
      ];
    }
    return $local_tasks;
  }

  /**
   * Determines if this entity type's ID is stored as an integer.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   An entity type.
   *
   * @return bool
   *   TRUE if this entity type's ID key is always an integer, FALSE otherwise.
   */
  protected function hasIntegerId(EntityTypeInterface $entity_type) {
    $field_storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type->id());
    return $field_storage_definitions[$entity_type->getKey('id')]->getType() === 'integer';
  }

  /**
   * Returns an array of relevant entity types.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface[]
   *   An array of entity types.
   */
  protected function getEntityTypes() {
    return array_filter($this->entityTypeManager->getDefinitions(), function (EntityTypeInterface $entity_type) {
      return $entity_type->entityClassImplements(FieldableEntityInterface::class) && $entity_type->hasViewBuilderClass() && $entity_type->hasLinkTemplate('canonical');
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSectionStorage() {
    // @todo Expand to work for all view modes in
    //   https://www.drupal.org/node/2907413.
    return LayoutBuilderEntityViewDisplay::collectRenderDisplay($this->getEntity(), 'full');
  }

  /**
   * {@inheritdoc}
   */
  public function getRedirectUrl() {
    return $this->getEntity()->toUrl('canonical');
  }

  /**
   * {@inheritdoc}
   */
  public function getLayoutBuilderUrl($rel = 'view') {
    $entity = $this->getEntity();
    $route_parameters[$entity->getEntityTypeId()] = $entity->id();
    return Url::fromRoute("layout_builder.{$this->getStorageType()}.{$this->getEntity()->getEntityTypeId()}.$rel", $route_parameters);
  }

  /**
   * {@inheritdoc}
   */
  public function getContextsDuringPreview() {
    $contexts = parent::getContextsDuringPreview();

    // @todo Remove this in https://www.drupal.org/node/3018782.
    if (isset($contexts['entity'])) {
      $contexts['layout_builder.entity'] = $contexts['entity'];
      unset($contexts['entity']);
    }
    return $contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->getEntity()->label();
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    return $this->getEntity()->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($operation, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $default_section_storage = $this->getDefaultSectionStorage();
    $result = AccessResult::allowedIf($default_section_storage->isLayoutBuilderEnabled())->addCacheableDependency($default_section_storage);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
