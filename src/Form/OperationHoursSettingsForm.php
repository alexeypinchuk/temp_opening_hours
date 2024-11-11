<?php

namespace Drupal\operation_hours\Form;

use Drupal\Component\Datetime\DateTimePlus;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Operation hours config form.
 */
class OperationHoursSettingsForm extends ConfigFormBase {

  const string OPERATION_HOURS_TIME_FORMAT = 'H:i';

  const string OPERATION_HOURS_SEASON_BEGIN_FORMAT = 'm/d';

  const string OPERATION_HOURS_DAY_FORMAT = 'd/m/Y';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'operation_hours_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['operation_hours.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;

    $form['#attached']['library'][] = 'operation_hours/settings_form';

    // Attached time picker library.
    $form['#attached']['library'][] = 'time_picker/time_picker';
    $form['#attached']['drupalSettings']['time_picker'] = [
      'hour_format' => '24h',
      'theme_color' => 'theme_default',
    ];

    $form['#attached']['drupalSettings']['time_range_picker'] = [
      'hour_format' => '24h',
      'theme_color' => 'theme_default',
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block title'),
      '#default_value' => $this->config('al_opening_hours.opening_hours.settings')->get('title'),
    ];

    $form['subtitle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block subtitle'),
      '#default_value' => $this->config('al_opening_hours.opening_hours.settings')->get('subtitle'),
    ];

    $form['status'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Status'),
      '#default_value' => $this->config('al_opening_hours.opening_hours.settings')->get('status'),
    ];

    $form['description'] = [
      '#type' => 'text_format',
      '#format' => 'standart',
      '#allowed_formats' => ['standart'],
      '#title' => $this->t('Description'),
      '#default_value' => $this->config('al_opening_hours.opening_hours.settings')->get('description')['value'],
    ];

    $form['description_hidden'] = [
      '#type' => 'text_format',
      '#format' => 'standart',
      '#allowed_formats' => ['standart'],
      '#title' => $this->t('Description hidden part'),
      '#default_value' => $this->config('al_opening_hours.opening_hours.settings')->get('description_hidden')['value'],
    ];

    $this->buildSeasonSection($form, $form_state);

    $this->buildExceptionsSection($form, $form_state);

    return parent::buildForm($form, $form_state);
  }

  /**
   * Build season section.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function buildSeasonSection(array &$form, FormStateInterface $form_state) : void {
    $form['seasons'] = [
      '#type' => 'details',
      '#title' => $this->t('Operation Hours'),
      '#open' => TRUE,
    ];

    $seasons = $this->config('operation_hours.settings')->get('seasons');
    foreach ($seasons as $name => $season) {
      $form['seasons'][$name] = [
        '#type' => 'fieldset',
        '#theme' => 'operation_hours_season_table',
        '#title' => strtoupper($name),
      ];
      $form['seasons'][$name]['begin'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Begin'),
        '#default_value' => $season['begin'],
        '#element_validate' => ['::elementValidateBeginDate'],
      ];
      foreach ($season['days'] as $day_name => $day) {
        $form['seasons'][$name]['days'][$day_name] = [
          '#type' => 'container',
        ];
        $form['seasons'][$name]['days'][$day_name]['name'] = [
          '#type' => '#markup',
          '#markup' => strtoupper($day_name),
        ];
        $status_field_name = "seasons[$name][days][$day_name][closed]";
        $form['seasons'][$name]['days'][$day_name]['from'] = [
          '#type' => 'textfield',
          '#title' => $this->t('From'),
          '#default_value' => $this->prepareTime($day['from']),
          '#title_display' => 'invisible',
          '#size' => 6,
          '#maxlength' => 6,
          '#attributes' => [
            'class' => ['timepicker'],
          ],
          '#element_validate' => ['::elementValidateTime'],
          '#states' => [
            'required' => [
              ":input[name=\"$status_field_name\"]" => ['unchecked' => TRUE],
            ],
          ],
        ];
        $form['seasons'][$name]['days'][$day_name]['to'] = [
          '#type' => 'textfield',
          '#title' => $this->prepareTime($this->t('To')),
          '#default_value' => $day['to'],
          '#title_display' => 'invisible',
          '#size' => 6,
          '#maxlength' => 6,
          '#attributes' => [
            'class' => ['timepicker'],
          ],
          '#element_validate' => [
            '::elementValidateTime',
            '::elementValidateTimeTo',
          ],
          '#states' => [
            'required' => [
              ":input[name=\"$status_field_name\"]" => ['unchecked' => TRUE],
            ],
          ],
        ];
        $form['seasons'][$name]['days'][$day_name]['closed'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Closed'),
          '#default_value' => $day['closed'],
          '#title_display' => 'invisible',
        ];
      }
    }
  }

  /**
   * Build exceptions section.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function buildExceptionsSection(array &$form, FormStateInterface $form_state) {
    $form['exceptions'] = [
      '#type' => 'details',
      '#title' => $this->t('Exceptions'),
      '#open' => TRUE,
      '#prefix' => '<div id="exceptions-fieldset-wrapper">',
      '#suffix' => '</div>',
      '#element_validate' => [[$this, 'elementExceptionsValidate']],
    ];

    $id_prefix = 'exceptions';
    $wrapper_id = Html::getUniqueId($id_prefix . '-add-more-wrapper');
    $form['exceptions']['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['exceptions']['#suffix'] = '</div>';
    $exceptions = $this->getExceptions($form_state);

    foreach ($exceptions as $key => $exception) {
      $form['exceptions'][$key] = [
        '#type' => 'fieldset',
        '#theme' => 'operation_hours_exception_table',
        '#exception_key' => $key,
      ];

      $form['exceptions'][$key]['day'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Day'),
        '#date_date_element' => 'date',
        '#date_time_element' => 'none',
        '#date_date_format' => static::OPERATION_HOURS_DAY_FORMAT,
        '#default_value' => isset($exception['day']) ? $this->prepareDate($exception['day']) : DrupalDateTime::createFromTimestamp(time()),
      ];

      $form['exceptions'][$key]['status'] = [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => [
          0 => $this->t('Closed'),
          1 => $this->t('Open'),
        ],
        '#default_value' => isset($exception['status']) ? $exception['status'] : 0,
      ];

      $status_field_name = "exceptions[$key][status]";
      $form['exceptions'][$key]['from'] = [
        '#type' => 'textfield',
        '#title' => $this->t('From'),
        '#default_value' => isset($exception['from']) ? $this->prepareTime($exception['from']) : '',
        '#size' => 6,
        '#maxlength' => 6,
        '#attributes' => [
          'class' => ['timepicker'],
        ],
        '#element_validate' => ['::elementValidateTime'],
        '#states' => [
          'required' => [
            ":input[name=\"$status_field_name\"]" => ['value' => 1],
          ],
        ],
      ];

      $form['exceptions'][$key]['to'] = [
        '#type' => 'textfield',
        '#title' => $this->t('To'),
        '#default_value' => isset($exception['to']) ? $this->prepareTime($exception['to']) : '',
        '#size' => 6,
        '#maxlength' => 6,
        '#attributes' => [
          'class' => ['timepicker'],
        ],
        '#element_validate' => [
          '::elementValidateTime',
          '::elementValidateTimeTo',
        ],
        '#states' => [
          'required' => [
            ":input[name=\"$status_field_name\"]" => ['value' => 1],
          ],
        ],
      ];

      $form['exceptions'][$key]['remove_' . $key] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_' . $key,
        '#submit' => [[static::class, 'removeSubmit']],
        '#limit_validation_errors' => [['exceptions', $key, 'remove_' . $key]],
        '#ajax' => [
          'callback' => [static::class, 'removeAjax'],
          'wrapper' => $wrapper_id,
          'effect' => 'fade',
        ],
      ];
    }

    $form['exceptions']['add_more'] = [
      '#type' => 'submit',
      '#name' => strtr($id_prefix, '-', '_') . '_add_more',
      '#value' => $this->t('Add another item'),
      '#attributes' => ['class' => ['add-more-submit']],
      '#limit_validation_errors' => [['exceptions', 'add_more']],
      '#submit' => [[static::class, 'addMoreSubmit']],
      '#ajax' => [
        'callback' => [static::class, 'addMoreAjax'],
        'wrapper' => $wrapper_id,
        'effect' => 'fade',
      ],
    ];
  }

  /**
   * Validate season begin date element.
   */
  public function elementValidateBeginDate(array $element, FormStateInterface $form_state) {
    if (!$element['#value']) {
      return;
    }

    try {
      $date = DateTimePlus::createFromFormat(static::OPERATION_HOURS_SEASON_BEGIN_FORMAT, $element['#value']);
      return new DrupalDateTime($date->format('Y-m-d') . ' 00:00:00');
    }
    catch (\InvalidArgumentException) {
      $form_state->setError($element, $this->t('@label element value is incorrect format', ['@label' => $element['#title']]));
    }
  }

  /**
   * Validate time element.
   */
  public function elementValidateTime(array $element, FormStateInterface $form_state) {
    if (!$element['#value']) {
      return;
    }

    try {
      DateTimePlus::createFromFormat(static::OPERATION_HOURS_TIME_FORMAT, $element['#value']);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setError($element, $this->t('@label element value is incorrect format', ['@label' => $element['#title']]));
    }
  }

  /**
   * Validate time element.
   */
  public function elementValidateTimeTo(array $element, FormStateInterface $form_state) {
    $parents = $element['#parents'];
    $from_parents = $parents;
    array_pop($from_parents);
    $from_parents[] = 'from';
    $values = $form_state->getValues();
    $from_value = NestedArray::getValue($values, $from_parents);
    if (!$element['#value'] || !$from_value) {
      return;
    }

    if ($element['#value'] <= $from_value) {
      $form_state->setError($element, $this->t("@label element value can't be less than from time", ['@label' => $element['#title']]));
    }
  }

  /**
   * Validate exceptions container element.
   */
  public function elementExceptionsValidate($element, FormStateInterface $form_state, $form) {
    $storage = &$form_state->getStorage();
    $exceptions = $form_state->getValue('exceptions', []);

    $exceptions = array_filter($exceptions, function ($key) {
      return is_int($key);
    }, ARRAY_FILTER_USE_KEY);

    NestedArray::setValue($storage, ['exceptions'], $exceptions);
  }

  /**
   * Ajax callback to remove a element from a multi-valued field.
   */
  public function removeAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -2));
  }

  /**
   * Submission handler for the "Remove" button.
   */
  public static function removeSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();
    $removed_key = str_replace('remove_', '', $button['#name']);

    $storage = &$form_state->getStorage();

    $exceptions = NestedArray::getValue($storage, ['exceptions']);
    unset($exceptions[$removed_key]);
    NestedArray::setValue($storage, ['exceptions'], $exceptions);
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Add another item" button.
   *
   * This returns the new page content to replace the page content made obsolete
   * by the form submission.
   */
  public static function addMoreAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
  }

  /**
   * Submission handler for the "Add another item" button.
   */
  public static function addMoreSubmit(array $form, FormStateInterface $form_state) {
    $storage = &$form_state->getStorage();

    $exceptions = NestedArray::getValue($storage, ['exceptions']);
    $exceptions[] = [];
    NestedArray::setValue($storage, ['exceptions'], $exceptions);

    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $exception_properties = [
      'status',
      'from',
      'to',
      'day',
    ];
    $exceptions = $form_state->getValue('exceptions');
    foreach ($exceptions as $key => $exception) {
      if (!is_int($key)) {
        unset($exceptions[$key]);
        continue;
      }

      foreach ($exception as $exception_property => $exception_value) {
        if (!in_array($exception_property, $exception_properties)) {
          unset($exceptions[$key][$exception_property]);
          continue;
        }

        if ($exception_property === 'day') {
          $exceptions[$key][$exception_property] = $exception_value->format(self::OPERATION_HOURS_DAY_FORMAT);
        }
      }
    }

    $this->config('operation_hours.settings')
      ->set('title', $form_state->getValue('title'))
      ->set('subtitle', $form_state->getValue('subtitle'))
      ->set('status', $form_state->getValue('status'))
      ->set('description', $form_state->getValue('description'))
      ->set('description_hidden', $form_state->getValue('description_hidden'))
      ->set('seasons', $form_state->getValue('seasons'))
      ->set('exceptions', $exceptions)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get prepared time.
   *
   * @param mixed $time
   *   Time string.
   *
   * @return string
   *   Returns prepared time.
   */
  public function prepareTime($time) : string {
    if (!$time) {
      return '';
    }

    try {
      $time = DateTimePlus::createFromFormat(static::OPERATION_HOURS_TIME_FORMAT, $time);
      return $time->format(static::OPERATION_HOURS_TIME_FORMAT);
    }
    catch (\InvalidArgumentException $e) {
      return '';
    }
  }

  /**
   * Prepare date.
   *
   * @param mixed $date
   *   Date sting.
   *
   * @return \Drupal\Core\Datetime\DrupalDateTime|string
   *   Returns prepared date.
   */
  public function prepareDate($date) {
    if (!$date) {
      return '';
    }

    try {
      $date = DateTimePlus::createFromFormat(static::OPERATION_HOURS_DAY_FORMAT, $date);
      return new DrupalDateTime($date->format('Y-m-d') . ' 00:00:00');
    }
    catch (\InvalidArgumentException $e) {
      return '';
    }
  }

  /**
   * Get exceptions.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return mixed
   *   Returns exceptions.
   */
  public function getExceptions(FormStateInterface $form_state) {
    $storage = &$form_state->getStorage();
    $exist = TRUE;
    $exceptions = NestedArray::getValue($storage, ['exceptions'], $exist);

    if (!$exist) {
      $exceptions = $this->config('operation_hours.settings')->get('exceptions');
    }

    return $exceptions;
  }

}
