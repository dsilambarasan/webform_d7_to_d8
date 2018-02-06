<?php

namespace Drupal\webform_d7_to_d8;

use Drupal\webform_d7_to_d8\traits\Utilities;
use Drupal\webform_d7_to_d8\Collection\Components;
use Drupal\webform_d7_to_d8\Collection\Submissions;
use Drupal\webform\Entity\Webform as DrupalWebform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Represents a webform.
 */
class Webform {

  use Utilities;

  /**
   * Constructor.
   *
   * @param int $nid
   *   The legacy Drupal node ID.
   * @param string $title
   *   The title of the legacy node which will become the title of the new
   *   webform (which, in Drupal 8, is not a node).
   * @param array $options
   *   Options originally passed to the migrator (for example ['nid' => 123])
   *   and documented in ./README.md.
   */
  public function __construct(int $nid, string $title, array $options) {
    $this->nid = $nid;
    $this->title = $title;
    $this->options = $options;
  }

  /**
   * Delete all submissions for this webform on the Drupal 8 database.
   *
   * @throws Exception
   */
  public function deleteSubmissions() {
    if (isset($this->options['simulate']) && $this->options['simulate']) {
      $this->print('SIMULATE: Delete submissions for webform before reimporting them.');
      return;
    }
    $query = $this->getConnection('default')->select('webform_submission', 'ws');
    $query->condition('ws.webform_id', 'webform_' . $this->getNid());
    $query->addField('ws', 'sid');
    $result = array_keys($query->execute()->fetchAllAssoc('sid'));

    $max = \Drupal::state()->get('webform_d7_to_d8_max_delete_items', 500);
    $this->print('Will delete @n submissions in chunks of @c to avoid avoid out of memory errors.', ['@n' => count($result), '@c' => $max]);

    $arrays = array_chunk($result, $max);

    $this->print('@n chunks generated.', ['@n' => count($arrays)]);

    $storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
    foreach ($arrays as $array) {
      $submissions = WebformSubmission::loadMultiple($array);
      $this->print('Deleting @n submissions for webform @f', ['@n' => count($submissions), '@f' => $webform_id]);
      $storage->delete($submissions);
    }
  }

  /**
   * Return the first sid (submission id) to import.
   */
  public function firstSid() {
    return \Drupal::state()->get('webform_d7_to_d8', 0);
  }

  /**
   * Get the Drupal 8 Webform object.
   *
   * @return Drupal\webform\Entity\Webform
   *   The Drupal webform ojbect as DrupalWebform.
   */
  public function getDrupalObject() : DrupalWebform {
    return $this->drupalObject;
  }

  /**
   * Getter for nid.
   *
   * @return int
   *   The webform nid.
   */
  public function getNid() {
    return $this->nid;
  }

  /**
   * Import this webform, all its components and all its submissions.
   *
   * @param array $options
   *   Options originally passed to the migrator (for example ['nid' => 123])
   *   and documented in ./README.md.
   *
   * @throws \Exception
   */
  public function process($options = []) {
    $nid = $this->getNid();
    /* Use this if source and destination nid does not match.
    $tablePrefix = $this->getConnection('upgrade')->tablePrefix();
    if ($tablePrefix == TABLEPREFIX) {
      $destid = db_query('select destid1 from migrate_map_upgrade_d7_node_webform where sourceid1=' . $this->getNid())->fetchField();
      if ($destid != '') {
        $nid = $destid;
      }
    }
    */
    $this->drupalObject = $this->updateD8Webform([
      'id' => 'webform_' . $nid,
      'title' => $this->title,
    ], $options);

    //Components settings.
    $components = $this->webformComponents();
    $this->print($this->t('Form @n: Processing components', ['@n' => $this->getNid()]));
    $this->updateD8Components($this->getDrupalObject(), $components->toFormArray(), $this->options);

    // Form settings.
    $webformSettings = current($this->webformFormSettings());
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('webform.webform.webform_' . $nid);
    $webformStatus = $webformSettings->status == 1 ? 'open' : 'closed';
    $config->set('status', $webformStatus)->save();

    $config = $config_factory->getEditable('webform.webform.webform_' . $nid);
    $settings = $config->get('settings');
    $settings['confirmation_type'] = 'page' ;
    if ($webformSettings->confirmation != '' && $webformSettings->redirect_url != '<confirmation>') {
      $settings['confirmation_type'] = 'url_message';
    }
    elseif ($webformSettings->confirmation == '' && $webformSettings->redirect_url != '<confirmation>') {
      $settings['confirmation_type'] = 'url';
    }
    elseif ($webformSettings->redirect_url == '<none>') {
      $settings['confirmation_type'] = 'inline';
    }
    $settings['confirmation_message'] = $webformSettings->confirmation;
    $config->set('settings', $settings)->save();

    //Email settings.
    $templates = $this->emailTemplates();
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('webform.webform.webform_' . $nid);
    $handlers = $config->get('handlers');
    foreach ($templates as $key => $value) {
      $ekey = $key == 0 ? 'email' : 'email_' . $key;
      $handlers[$ekey] = [
        'id' => 'email',
        'label' => 'Email',
        'handler_id' => $ekey,
        'status' => 1,
        'conditions' => [],
        'weight' => 0
      ];
      $handlers[$ekey]['settings']['from_name'] = $value->from_name;
      $handlers[$ekey]['settings']['from_mail'] = $value->from_address;
      $handlers[$ekey]['settings']['to_mail'] = $value->email;
      $handlers[$ekey]['settings']['subject'] = $value->subject;
      $handlers[$ekey]['settings']['body'] = str_replace('submission:value', 'webform_submission:value', $value->template);
      $config->set('handlers', $handlers)->save();
    }

    // Webform Submissions.
    $submissions = $this->webformSubmissions()->toArray();
    foreach ($submissions as $submission) {
      $this->print($this->t('Form @n: Processing submission @s', ['@n' => $this->getNid(), '@s' => $submission->getSid()]));
      try {
        $submission->process();
      }
      catch (\Throwable $t) {
        $this->print('ERROR with submission (errors and possible fixes will be shown at the end of the process)');
        WebformMigrator::instance()->addError($t->getMessage());
      }
    }
    #$node = node_load($this->getNid());
    $node = node_load($nid);
    if (isset($this->options['simulate']) && $this->options['simulate']) {
      $this->print('SIMULATE: Linking node to the webform we just created.');
    }
    elseif ($node) {
      try {
        $this->print('Linking node @n to the webform we just created.', ['@n' => $this->getNid()]);
        #$node->webform->target_id = 'webform_' . $this->getNid();
        $node->webform->target_id = 'webform_' . $nid;
        $node->save();
      }
      catch (\Exception $e) {
        $this->print('Node @n exists on the target environment, but we could not set the webform field to the appropriate webform, moving on...', ['@n' => $this->getNid()]);
      }
    }
    else {
      $this->print('Node @n does not exist on the target environment, moving on...', ['@n' => $this->getNid()]);
    }
  }

  /**
   * Set the Drupal 8 Webform object.
   *
   * @param Drupal\webform\Entity\Webform $webform
   *   The Drupal webform ojbect as DrupalWebform.
   */
  public function setDrupalObject(DrupalWebform $webform) {
    $this->drupalObject = $webform;
  }

  /**
   * Get all legacy submitted data for this webform.
   *
   * @return array
   *   Submissions keyed by legacy sid (submission ID).
   *
   * @throws Exception
   */
  public function submittedData() : array {
    $return = [];
    $query = $this->getConnection('upgrade')->select('webform_submitted_data', 'wd');
    $query->join('webform_component', 'c', 'c.cid = wd.cid AND c.nid = wd.nid');
    $query->join('webform_submissions', 'ws', 'ws.nid = wd.nid AND ws.sid = wd.sid');
    $query->addField('c', 'form_key');
    $query->addField('wd', 'sid');
    $query->addField('wd', 'data');
    $query->addField('ws', 'uid');
    $query->addField('ws', 'remote_addr');
    $query->addField('ws', 'submitted');
    $query->condition('wd.nid', $this->getNid(), '=');
    $result = $query->execute()->fetchAll();
    $return = [];
    foreach ($result as $row) {
      $return[$row->sid][$row->form_key] = [
        'value' => $row->data,
      ];
      $return[$row->sid]['extra']['uid'] = $row->uid;
      $return[$row->sid]['extra']['remote_addr'] = $row->remote_addr;
      $return[$row->sid]['extra']['submitted'] = $row->submitted;
    }
    return $return;
  }

  /**
   * Get all legacy components for a given webform.
   *
   * @return Components
   *   The components.
   *
   * @throws \Exception
   */
  public function webformComponents() : Components {
    $query = $this->getConnection('upgrade')->select('webform_component', 'wc');
    $query->addField('wc', 'cid');
    $query->addField('wc', 'form_key');
    $query->addField('wc', 'name');
    $query->addField('wc', 'mandatory');
    $query->addField('wc', 'type');
    $query->addField('wc', 'extra');
    $query->addField('wc', 'value');
    $query->addField('wc', 'pid');
    $query->condition('nid', $this->getNid(), '=');
    $query->orderBy('weight');

    $result = $query->execute()->fetchAllAssoc('cid');
    $array = [];
    foreach ($result as $cid => $info) {
      $array[] = ComponentFactory::instance()->create($this, $cid, (array) $info, $this->options);
    }
    return new Components($array);
  }

  /**
   * Get all legacy submissions for a given webform.
   *
   * @return Submissions
   *   The submissions.
   *
   * @throws \Exception
   */
  public function webformSubmissions() : Submissions {
    if (isset($this->options['max_submissions']) && $this->options['max_submissions'] !== NULL) {
      $max = $this->options['max_submissions'];
      if ($max === 0) {
        $this->print('You speicifc max_submissions to 0, so no submissions will be loaded.');
        return new Submissions([]);
      }
    }

    $this->print('Only getting submission ids > @s because we have already imported the others.', ['@s' => $this->firstSid()]);

    $query = $this->getConnection('upgrade')->select('webform_submissions', 'ws');
    $query->addField('ws', 'sid');
    $query->condition('nid', $this->getNid(), '=');
    $query->condition('sid', $this->firstSid(), '>');

    if (isset($max)) {
      $this->print('You speicifc max_submissions to @n, so only some submissions will be processed.', ['@n' => $max]);
      $query->range(0, $max);
    }
    $submitted_data = $this->submittedData();

    $result = $query->execute()->fetchAllAssoc('sid');
    $array = [];
    foreach ($result as $sid => $info) {
      if (empty($submitted_data[$sid])) {
        $this->print('In the legacy system, there is a submission with');
        $this->print('id @id, but it does not have any associated data.', ['@id' => $sid]);
        $this->print('Ignoring it and moving on...');
        continue;
      }
      $this->print('Importing submission @s', ['@s' => $sid]);
      $array[] = new Submission($this, $sid, (array) $info, $submitted_data[$sid], $this->options);
    }

    return new Submissions($array);
  }

  /**
   * Returns all email templates/configuration for a webform.
   */
  public function emailTemplates() {
    $query = $this->getConnection('upgrade')->select('webform_component', 'wc');
    $query->addField('wc', 'cid');
    $query->addField('wc', 'form_key');
    $query->condition('nid', $this->getNid(), '=');

    $result = $query->execute()->fetchAllAssoc('cid');
    $array = [];
    foreach ($result as $cid => $info) {
      $array[$cid] = $info->form_key;
    }

    $query = $this->getConnection('upgrade')->select('webform_emails', 'we');
    $query->fields('we');
/*    $query->addField('we', 'eid');
    $query->addField('we', 'email');
    $query->addField('we', 'subject');
    $query->addField('we', 'from_name');
    $query->addField('we', 'from_address');
    $query->addField('we', 'template');
    $query->addField('we', 'excluded_components');
    $query->addField('we', 'html');
    $query->addField('we', 'attachments'); */
    $query->condition('nid', $this->getNid(), '=');
    $query->orderBy('eid');

    $result = $query->execute()->fetchAllAssoc('eid');
    foreach ($result as $eid => $info) {
      $info->email = isset($array[$info->email]) ? '[webform_submission:values:' . $array[$info->email] . ':raw]' : $info->email;
      $info->from_name = isset($array[$info->from_name]) ? '[webform_submission:values:' . $array[$info->from_name] . ':raw]' : $info->from_name;
      $info->from_address = isset($array[$info->from_address]) ? '[webform_submission:values:' . $array[$info->from_address] . ':raw]' : $info->from_address;
      $emails[$eid] = $info;
    }
    return $emails;
  }

  /**
   * Returns all webform settings for a node/webform. 
   */
  public function webformFormSettings() {
    $query = $this->getConnection('upgrade')->select('webform', 'w');
    $query->fields('w');
    $query->condition('nid', $this->getNid(), '=');

    $result = $query->execute()->fetchAllAssoc('nid');
    return $result;
  }

}
