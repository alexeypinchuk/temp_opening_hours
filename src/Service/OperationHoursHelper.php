<?php

namespace Drupal\operation_hours\Service;

use Drupal\Component\Datetime\DateTimePlus;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Operation hours helper service.
 */
class OperationHoursHelper {

  const string OPERATION_HOURS_CONFIG_NAME = 'operation_hours.settings';

  const string OPERATION_HOURS_TIMEZONE = 'Europe/Luxembourg';

  const string OPERATION_HOURS_STATE_CACHE_TAG = 'operation_hours:state';

  /**
   * Operation hours config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * The state key/value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Current date.
   *
   * @var \Drupal\Component\Datetime\DateTimePlus
   */
  protected DateTimePlus $nowDate;

  /**
   * Operation hours helper.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key value store.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state) {
    $this->config = $config_factory->get(static::OPERATION_HOURS_CONFIG_NAME);
    $this->state = $state;
  }

  /**
   * Get operation hours config.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   Returns operation hours config.
   */
  public function config() : ImmutableConfig {
    return $this->config;
  }

  /**
   * Get current season name.
   *
   * @return string
   *   Returns current season name.
   */
  public function getCurrentSeasonName() : string {
    $seasons = $this->config->get('seasons');

    $current_date = new DateTimePlus();
    $current_year = (int) $current_date->format('Y');
    $previous_year = $current_year - 1;

    // The main idea is to calculate correct season start date. Season can't
    // started before current date. So we determine season year and sort it.
    $seasons_starts = [];
    foreach ($seasons as $name => $season) {
      $season_start = DateTimePlus::createFromFormat('m/d/Y', "{$season['begin']}/$current_year");

      $diff = $current_date->diff($season_start);
      if ($diff->format('%r%a') <= 0) {
        $seasons_starts[$name] = $season_start;
      }
      else {
        $seasons_starts[$name] = DateTimePlus::createFromFormat('m/d/Y', "{$season['begin']}/$previous_year");
      }
    }

    uasort($seasons_starts, function ($first, $second) {
      return intval($first->diff($second)->format('%r%a'));
    });

    return array_key_first($seasons_starts);
  }

  /**
   * Check whether date is today.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $date
   *   Date object.
   *
   * @return bool
   *   Returns TRUE if date is today.
   */
  public function isToday(DateTimePlus $date) : bool {
    if ($this->nowDate->diff($date)->format('%a') < 1) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check whether date is tomorrow.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $date
   *   Date object.
   *
   * @return bool
   *   Returns TRUE if date is tomorrow.
   */
  public function isTomorrow(DateTimePlus $date) : bool {
    if ($this->nowDate->diff($date)->format('%a') < 2) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Get operation hours.
   *
   * @return array
   *   Returns operation hours.
   */
  public function getOperationHours() : array {
    $operation_hours = &drupal_static(__METHOD__);

    if (isset($operation_hours)) {
      return $operation_hours;
    }

    if (empty($this->nowDate)) {
      $this->nowDate = new DateTimePlus('now', new \DateTimeZone(self::OPERATION_HOURS_TIMEZONE));
    }

    $date = clone $this->nowDate;
    return $this->determineOperationHours($date);
  }

  /**
   * Invalidate operation hours tag if needed.
   */
  public function checkOperationHoursTag() : void {
    $operation_hours = $this->getOperationHours();
    $date = $operation_hours['day'];

    $operation_hours['day'] = $date->format('N');
    if ($this->isToday($date)) {
      $operation_hours['day'] = 'today';
    }
    elseif ($this->isTomorrow($date)) {
      $operation_hours['day'] = 'tomorrow';
    }

    $prev_hash = $this->state->get('operation_hours_actual_state', FALSE);
    $current_hash = Crypt::hmacBase64(serialize($operation_hours), Settings::getHashSalt());

    if ($prev_hash !== $current_hash) {
      Cache::invalidateTags([self::OPERATION_HOURS_STATE_CACHE_TAG]);
      $this->state->set('operation_hours_actual_state', $current_hash);
    }
  }

  /**
   * Calculate actual operation hours.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $date
   *   Date object.
   *
   * @return array
   *   Returns prepared operation hours.
   */
  protected function determineOperationHours(DateTimePlus $date) : array {
    // Skip checking if we check all next week.
    if ($this->checkWeekLimit($date)) {
      return [];
    }

    // Check exceptions. Exception has bigger priority than season configs, so
    // we skip season checking if there is closed or open exception day.
    if ($exception = $this->getException($date)) {
      if (!$exception['status']) {
        return $this->determineOperationHours($date->modify('+1 day'));
      }

      return $this->prepareOperationHours($date, $exception['from'], $exception['to']);
    }

    // Check season configs.
    $seasons = $this->config->get('seasons');
    $season = $seasons[$this->getCurrentSeasonName()];
    $day = strtolower($date->format('l'));

    if ($season['days'][$day]['closed']) {
      return $this->determineOperationHours($date->modify('+1 day'));
    }

    if (!$this->isToday($date)) {
      return $this->prepareOperationHours($date, $season['days'][$day]['from'], $season['days'][$day]['to']);
    }

    if (!$this->isWorkDayEnded($date, $season['days'][$day]['to'])) {
      return $this->prepareOperationHours($date, $season['days'][$day]['from'], $season['days'][$day]['to']);
    }

    return $this->determineOperationHours($date->modify('+1 day'));
  }

  /**
   * Check whether working day is ended.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $date
   *   Date object.
   * @param string $to
   *   To hours.
   *
   * @return bool
   *   Returns TRUE if working day ended.
   */
  protected function isWorkDayEnded(DateTimePlus $date, string $to) : bool {
    return $date->format('H:i') > $to;
  }

  /**
   * Get exception is equivalent the date.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $date
   *   Date object.
   *
   * @return false|array
   *   Returns exception if exists.
   */
  protected function getException(DateTimePlus $date) : false|array {
    $exceptions = $this->config->get('exceptions');
    foreach ($exceptions as $exception) {
      $exception_day = DateTimePlus::createFromFormat('d/m/Y', $exception['day'], new \DateTimeZone(static::OPERATION_HOURS_TIMEZONE));
      if ($exception_day->diff($date)->format('%a') != 0) {
        continue;
      }

      if (!$exception['status']) {
        return $exception;
      }

      if (!$this->isToday($exception_day)) {
        return $exception;
      }

      if (!$this->isWorkDayEnded($date, $exception['to'])) {
        return $exception;
      }
    }

    return FALSE;
  }

  /**
   * Check week limit.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $date
   *   Date object.
   *
   * @return bool
   *   Returns TRUE if date is out week limit.
   */
  protected function checkWeekLimit(DateTimePlus $date) : bool {
    if ($this->nowDate->diff($date)->format('%a') > 7) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Prepare date operation hours.
   *
   * @param \Drupal\Component\Datetime\DateTimePlus $date
   *   Date object.
   * @param string $from
   *   From hours.
   * @param string $to
   *   To hours.
   *
   * @return array
   *   Returns prepared operation hours.
   */
  protected function prepareOperationHours(DateTimePlus $date, string $from, string $to) : array {
    return [
      'day' => $date,
      'from' => $from,
      'to' => $to,
    ];
  }

}
