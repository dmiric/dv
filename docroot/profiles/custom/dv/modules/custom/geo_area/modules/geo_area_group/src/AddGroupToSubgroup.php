<?php

namespace Drupal\dmt_group;

use Drupal\group\Entity\Group;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Class AddGroupToSubgroup
 *
 * @package Drupal\dmt_group
 */
class AddGroupToSubgroup {

  /** @var  string */
  protected $groupType;

  /** @var \Drupal\Core\Entity\ContentEntityInterface */
  protected $entity;

  /** @var \Drupal\group\Entity\GroupInterface */
  protected $group;

  /** @var \Drupal\group\Entity\GroupInterface */
  protected $parentGroup;

  /** @var  string */
  protected $fieldMachineName;

  /**
   * Creates a group. Adds entity to a group.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param $groupType
   * @param $fieldMachineName
   */
  public function add(ContentEntityInterface $entity, $groupType, $fieldMachineName) {

    $this->entity = $entity;
    $this->groupType = $groupType;
    $this->fieldMachineName = $fieldMachineName;

    // create a group
    $this->group = Group::create([
      'type' => $this->groupType,
      'uid' => 1,
      'label' => $entity->label()
    ]);

    // save new created group
    $this->group->save();

    // add location node to created group
    $this->group->addContent($entity, 'group_node:' . $entity->bundle());

    // get parent entity id
    $parentEntityId = $this->entity->get($fieldMachineName)->target_id;

    // if parent group exits
    if (!empty($parentEntityId) && $parentGroupId = $this->getParentGroupId($parentEntityId)) {
      // load parent group
      $this->parentGroup = Group::load($parentGroupId);
      // add group to parent group
      $this->addGroupToParentGroup();
    }

  }

  /**
   * Add Location node to parent group.
   */
  protected function addGroupToParentGroup() {
    // add to parent group
    $this->parentGroup->addContent($this->group, 'subgroup:' . $this->groupType);
  }

  /**
   * Find parent group.
   *
   * @param $parentEntityId
   * @return mixed
   */
  protected function getParentGroupId($parentEntityId) {
    $result = views_get_view_result('getparentgroup', 'rest_export_1', $parentEntityId);
    return $result[0]->groups_field_data_group_content_field_data_id;
  }

}