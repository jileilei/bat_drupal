<?php

use Drupal\DrupalExtension\Context\DrupalSubContextBase,
    Drupal\Component\Utility\Random;

use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Exception\PendingException;

use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode;

use Behat\Behat\Hook\Scope\BeforeScenarioScope,
    Behat\Behat\Hook\Scope\AfterScenarioScope;

use Behat\Behat\Context\CustomSnippetAcceptingContext;

use Drupal\DrupalDriverManager;


/**
 * Features context.
 */
class FeatureContext extends DrupalSubContextBase implements CustomSnippetAcceptingContext {

  /**
   * The Mink context
   *
   * @var Drupal\DrupalExtension\Context\MinkContext
   */
  private $minkContext;

  /**
   * Keep track of units so they can be cleaned up.
   *
   * @var array
   */
  public $units = array();

  /**
   * Keep track of Types so they can be cleaned up.
   *
   * @var array
   */
  public $Types = array();

  /**
   * Keep track of events so they can be cleaned up.
   *
   * @var array
   */
  public $events = array();

  /**
   * Keep track of event types so they can be cleaned up.
   *
   * @var array
   */
  public $eventTypes = array();

  /**
   * Keep track of created content types so they can be cleaned up.
   *
   * @var array
   */
  public $content_types = array();

  /**
   * Keep track of created fields so they can be cleaned up.
   *
   * @var array
   */
  public $fields = array();

  /**
   * Initializes context.
   * Every scenario gets its own context object.
   *
   * @param \Drupal\DrupalDriverManager $drupal
   *   The Drupal driver manager.
   */
  public function __construct(DrupalDriverManager $drupal) {
    parent::__construct($drupal);
  }

  public static function getAcceptedSnippetType() { return 'regex'; }

  /**
   * @BeforeScenario
   */
  public function before(BeforeScenarioScope $scope) {
    $environment = $scope->getEnvironment();
    $this->minkContext = $environment->getContext('Drupal\DrupalExtension\Context\MinkContext');
  }

  /**
   * @AfterScenario
   */
  public function after(AfterScenarioScope $scope) {
    foreach ($this->users as $user) {
      $query2 = new EntityFieldQuery();
      $query2->entityCondition('entity_type', 'bat_event')
        ->propertyCondition('uid', $user->uid);
      $result = $query2->execute();
      if (isset($result['bat_event'])) {
        $event_ids = array_keys($result['bat_event']);
        bat_event_delete_multiple($booking_ids);
      }
    }

    if (!empty($this->units)) {
      foreach ($this->units as $unit) {
        $unit->delete();
      }
    }

    if (!empty($this->Types)) {
      foreach ($this->Types as $type) {
        $type->delete();
      }
    }

    if (!empty($this->eventTypes)) {
      foreach ($this->eventTypes as $event_type) {
        $event_type->delete();
      }
    }

    if (!empty($this->events)) {
      bat_event_delete_multiple($this->events);
    }

    foreach ($this->content_types as $content_type) {
      node_type_delete($content_type);
    }

    foreach ($this->fields as $field) {
      field_delete_field($field);
    }

  }

  /**
   * @When /^I am on the "([^"]*)" type$/
   */
  public function iAmOnTheType($type_name) {
    $this->iAmDoingOnTheType('view', $type_name);
  }

  /**
   * @When /^I am editing the "([^"]*)" type$/
   */
  public function iAmEditingTheType($type_name) {
    $this->iAmDoingOnTheType('edit', $type_name);
  }

  /**
   * Asserts that a given node type is editable.
   */
  public function assertEditNodeOfType($type) {
    $node = (object) array('type' => $type);
    $saved = $this->getDriver()->createNode($node);
    $this->nodes[] = $saved;

    // Set internal browser on the node edit page.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid . '/edit'));
  }

  /**
   * Retrieves the last booking ID.
   *
   * @return int
   *   The last booking ID.
   *
   * @throws RuntimeException
   */
  protected function getLastBooking() {
    $efq = new EntityFieldQuery();
    $efq->entityCondition('entity_type', 'rooms_booking')
      ->entityOrderBy('entity_id', 'DESC')
      ->range(0, 1);
    $result = $efq->execute();
    if (isset($result['rooms_booking'])) {
      $return = key($result['rooms_booking']);
      return $return;
    }
    else {
      throw new RuntimeException('Unable to find the last booking');
    }
  }

  /**
   * Checks if one unit is being locked by a booking in a date range.
   * @param $unit_name
   * @param $start_date
   * @param $end_date
   * @param $status
   */
  protected function checkUnitLockedByLastBooking($unit_name, $start_date, $end_date, $status) {
    $booking_id = $this->getLastBooking();
    $expected_value = rooms_availability_assign_id($booking_id, $status);
    $this->checkUnitPropertyRange($unit_name, $start_date, $end_date, $expected_value, 'availability');
  }

  /**
   * Adds options field to any room_unit or room_unit_type entity.
   *
   * @param TableNode $table
   *   Table containing options definitions.
   * @param $wrapper
   *   The entity wrapper to attach the options.
   */
  protected function addOptionsToEntity(TableNode $table, $wrapper) {
    $delta = 0;
    if (isset($wrapper->rooms_booking_unit_options)) {
      $delta = count($wrapper->rooms_booking_unit_options);
    }

    foreach ($table->getHash() as $entityHash) {
      $wrapper->rooms_booking_unit_options[$delta] = $entityHash;
      $delta++;
    }
    $wrapper->save();
  }

  /**
   * Fills a field using JS to avoid event firing.
   * @param string $field
   * @param string$value
   *
   */
  protected function fillFieldByJS($field, $value) {
    $field = str_replace('\\"', '"', $field);
    $value = str_replace('\\"', '"', $value);
    $xpath = $this->getSession()->getPage()->findField($field)->getXpath();

    $element = $this->getSession()->getDriver()->getWebDriverSession()->element('xpath', $xpath);
    $elementID = $element->getID();
    $subscript = "arguments[0]";
    $script = str_replace('{{ELEMENT}}', $subscript, '{{ELEMENT}}.value = "' . $value . '"');
    return $this->getSession()->getDriver()->getWebDriverSession()->execute(array(
      'script' => $script,
      'args' => array(array('ELEMENT' => $elementID))
    ));
  }

  /**
   * Redirects user to the action page for the given unit.
   *
   * @param $action
   * @param $unit_name
   */
  protected function iAmDoingOnTheType($action, $type_name) {
    $unit_id = $this->findTypeByName($type_name);
    $url = "admin/bat/config/types/type/$type_id/$action";
    $this->getSession()->visit($this->locatePath($url));
  }

  /**
   * Returns a type_id from its name.
   *
   * @param $type_name
   * @return int
   * @throws RuntimeException
   */
  protected function findTypeByName($type_name) {
    $efq = new EntityFieldQuery();
    $efq->entityCondition('entity_type', 'bat_type')
      ->propertyCondition('name', $type_name);
    $results = $efq->execute();
    if ($results && isset($results['bat_type'])) {
      return key($results['bat_type']);
    }
    else {
      throw new RuntimeException('Unable to find that type');
    }
  }
}
