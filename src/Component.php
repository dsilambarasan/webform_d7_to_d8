<?php

namespace Drupal\webform_d7_to_d8;

use Drupal\webform_d7_to_d8\traits\Utilities;

/**
 * Represents a webform component.
 */
class Component {

  use Utilities;

  /**
   * Constructor.
   *
   * @param Webform $webform
   *   A webform to which this component belongs
   *   (is a \Drupal\webform_d7_to_d8\Webform).
   * @param int $cid
   *   A component ID on the legacy database.
   * @param array $info
   *   Extra info about the component, corresponds to an associative array
   *   of legacy column names.
   * @param array $options
   *   Options originally passed to the migrator (for example ['nid' => 123])
   *   and documented in ./README.md.
   */
  public function __construct(Webform $webform, int $cid, array $info, array $options) {
    $this->webform = $webform;
    $this->cid = $cid;
    $this->info = $info;
    $this->options = $options;
  }

  /**
   * Based on legacy data, create a Drupal 8 form element.
   *
   * @return array
   *   An associative array with keys '#title', '#type'...
   *
   * @throws Exception
   */
  public function createFormElement() : array {
    $info = $this->info;
    $data = unserialize($info['extra']);
    $return = [
      '#title' => $info['name'],
      '#type' => $info['type'],
      '#required' => $info['mandatory'],
      '#default_value' => $info['value'],
      '#description' => $data['description'],
      'pid' => $info['pid'],
      'cid' => $info['cid'],
      'form_key' => $info['form_key'],
    ];
    if ($info['type'] == 'select') {
      $items = explode(PHP_EOL, $data['items']);
      if ((count($items) == 1 && $data['multiple'] == 1) || ($data['multiple'] == 0 && $data['aslist'] == 0)) {
        $return['#type'] = 'radios';
      }
      elseif (count($items) > 1 && $data['multiple'] == 1 && $data['aslist'] == 0) {
        $return['#type'] = 'checkboxes';
      }
    }
    if ($info['type'] == 'file') {
      $return['#type'] = 'managed_file';
    }
    if ($info['type'] == 'time') {
      $return['#type'] = 'webform_time';
      $return['#time_format'] = 'g:i A';
    }
    if ($info['type'] == 'markup') {
      $return['#type'] = 'webform_markup';
      $return['#admin_title'] = $info['#title'];
      $return['#markup'] = $info['value'];
      unset($return['#default_value']);
    }
    if ($info['type'] == 'pagebreak') {
      $return['#type'] = 'webform_wizard_page';
      $return['#open'] = true;
      $return['#prev_button_label'] = 'Prev';
      $return['#next_button_label'] = 'Next';
    }
    if ($info['type'] == 'grid') {
      $return['#type'] = 'webform_custom_composite';
      $return['#multiple'] = false;
      $return['#multiple__header'] = false;
      $return['#multiple__sorting'] = false;
      $return['#multiple__operations'] = false;
      $options = array_filter(explode(PHP_EOL, $data['options']));
      foreach ($options as $value) {
        $value = str_replace("\r", '', $value);
        $coptions[$value] = $value;
      }
      $i=0;
      $questions = array_filter(explode(PHP_EOL, $data['questions']));
      foreach ($questions as $question) {
        #$key = $i == 0 ? '' : '_' . $i;
        $key = '';
        $base = preg_replace('/[^A-Za-z0-9\ ]/', '', trim($question));
        $base = strtolower(str_replace(' ', '_', $base));
        $question = str_replace("\r", '', $question);
        $return['#element'][$base . $key] = [
          '#type' => 'radios',
          '#options' => $coptions,
          '#title' => $question,
        ];
        $i++;
      }
      #print_r(json_encode($return)); exit;
    }
    $this->extraInfo($return);

    return $return;
  }

  /**
   * Add extra information to a form element if necessary.
   *
   * @param array $array
   *   An associative array with keys '#title', '#type'... This can be
   *   modified by this function if necessary.
   *
   * @throws Exception
   */
  public function extraInfo(&$array) {
  }

  /**
   * Get the legacy component ID.
   *
   * @return int
   *   The cid.
   */
  public function getCid() : int {
    return $this->cid;
  }

  /**
   * Return a form array with only the current element, keyed by form_key.
   *
   * @return array
   *   The result of ::createFormElement(), keyed by the form_key.
   */
  public function toFormArray() : array {
    $info = $this->info;
    return [
      $info['form_key'] => $this->createFormElement(),
    ];
  }

}
