<?php

/**
 * @file
 * Post update functions for the comment module.
 */

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Enable the comment admin view.
 */
function comment_post_update_enable_comment_admin_view() {
  $module_handler = \Drupal::moduleHandler();
  $entity_type_manager = \Drupal::entityTypeManager();

  // Save the comment delete action to config.
  $config_install_path = $module_handler->getModule('comment')->getPath() . '/' . InstallStorage::CONFIG_INSTALL_DIRECTORY;
  $storage = new FileStorage($config_install_path);
  $entity_type_manager
    ->getStorage('action')
    ->create($storage->read('system.action.comment_delete_action'))
    ->save();

  // Only create if the views module is enabled.
  if (!$module_handler->moduleExists('views')) {
    return;
  }

  // Save the comment admin view to config.
  $optional_install_path = $module_handler->getModule('comment')->getPath() . '/' . InstallStorage::CONFIG_OPTIONAL_DIRECTORY;
  $storage = new FileStorage($optional_install_path);
  $entity_type_manager
    ->getStorage('view')
    ->create($storage->read('views.view.comment'))
    ->save();
}

/**
 * Add comment settings.
 */
function comment_post_update_add_ip_address_setting() {
  $config_factory = \Drupal::configFactory();
  $settings = $config_factory->getEditable('comment.settings');
  $settings->set('log_ip_addresses', TRUE)
    ->save(TRUE);
}

/**
 * Update comments to be revisionable.
 */
function comment_post_update_make_comment_revisionable(&$sandbox) {
  $definition_update_manager = \Drupal::entityDefinitionUpdateManager();
  /** @var \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface $last_installed_schema_repository */
  $last_installed_schema_repository = \Drupal::service('entity.last_installed_schema.repository');

  $entity_type = $definition_update_manager->getEntityType('comment');
  $field_storage_definitions = $last_installed_schema_repository->getLastInstalledFieldStorageDefinitions('comment');

  // Update the entity type definition.
  $entity_keys = $entity_type->getKeys();
  $entity_keys['revision'] = 'revision_id';
  $entity_keys['revision_translation_affected'] = 'revision_translation_affected';
  $entity_type->set('entity_keys', $entity_keys);
  $entity_type->set('revision_table', 'comment_revision');
  $entity_type->set('revision_data_table', 'comment_field_revision');
  $revision_metadata_keys = [
    'revision_default' => 'revision_default',
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ];
  $entity_type->set('revision_metadata_keys', $revision_metadata_keys);

  // Update the field storage definitions and add the new ones required by a
  // revisionable entity type.
  $field_storage_definitions['langcode']->setRevisionable(TRUE);
  $field_storage_definitions['subject']->setRevisionable(TRUE);
  $field_storage_definitions['name']->setRevisionable(TRUE);
  $field_storage_definitions['mail']->setRevisionable(TRUE);
  $field_storage_definitions['homepage']->setRevisionable(TRUE);
  $field_storage_definitions['hostname']->setRevisionable(TRUE);
  $field_storage_definitions['created']->setRevisionable(TRUE);
  $field_storage_definitions['changed']->setRevisionable(TRUE);

  $field_storage_definitions['revision_id'] = BaseFieldDefinition::create('integer')
    ->setName('revision_id')
    ->setTargetEntityTypeId('comment')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision ID'))
    ->setReadOnly(TRUE)
    ->setSetting('unsigned', TRUE);

  $field_storage_definitions['revision_default'] = BaseFieldDefinition::create('boolean')
    ->setName('revision_default')
    ->setTargetEntityTypeId('comment')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Default revision'))
    ->setDescription(new TranslatableMarkup('A flag indicating whether this was a default revision when it was saved.'))
    ->setStorageRequired(TRUE)
    ->setInternal(TRUE)
    ->setTranslatable(FALSE)
    ->setRevisionable(TRUE);

  $field_storage_definitions['revision_translation_affected'] = BaseFieldDefinition::create('boolean')
    ->setName('revision_translation_affected')
    ->setTargetEntityTypeId('comment')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision translation affected'))
    ->setDescription(new TranslatableMarkup('Indicates if the last edit of a translation belongs to current revision.'))
    ->setReadOnly(TRUE)
    ->setRevisionable(TRUE)
    ->setTranslatable(TRUE);

  $field_storage_definitions['revision_created'] = BaseFieldDefinition::create('created')
    ->setName('revision_created')
    ->setTargetEntityTypeId('comment')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision create time'))
    ->setDescription(new TranslatableMarkup('The time that the current revision was created.'))
    ->setRevisionable(TRUE);
  $field_storage_definitions['revision_user'] = BaseFieldDefinition::create('entity_reference')
    ->setName('revision_user')
    ->setTargetEntityTypeId('comment')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision user'))
    ->setDescription(new TranslatableMarkup('The user ID of the author of the current revision.'))
    ->setSetting('target_type', 'user')
    ->setRevisionable(TRUE);
  $field_storage_definitions['revision_log_message'] = BaseFieldDefinition::create('string_long')
    ->setName('revision_log_message')
    ->setTargetEntityTypeId('comment')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision log message'))
    ->setDescription(new TranslatableMarkup('Briefly describe the changes you have made.'))
    ->setRevisionable(TRUE)
    ->setDefaultValue('');

  $definition_update_manager->updateFieldableEntityType($entity_type, $field_storage_definitions, $sandbox);

  return t('Comments have been converted to be revisionable.');
}
