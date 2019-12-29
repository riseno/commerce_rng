<?php

namespace Drupal\commerce_rng\Form;

use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Config\ConfigFactory;
use Drupal\rng\Entity\Group;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Condition\ConditionManager;
use Drupal\Core\Form\FormBase;
use Drupal\rng\EventManagerInterface;

/**
 * Form for configuring Commerce RNG integration.
 *
 * @todo update
 */
class EventCommerceForm extends FormBase {

  /**
   * The action manager service.
   *
   * @var \Drupal\Core\Action\ActionManager
   */
  protected $actionManager;

  /**
   * The condition manager service.
   *
   * @var \Drupal\Core\Condition\ConditionManager
   */
  protected $conditionManager;

  /**
   * The RNG event manager.
   *
   * @var \Drupal\rng\EventManagerInterface
   */
  protected $eventManager;

  /**
   * The redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The event entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $event;

  /**
   * Constructs a new EventAccessForm object.
   *
   * @param \Drupal\Core\Action\ActionManager $actionManager
   *   The action manager.
   * @param \Drupal\Core\Condition\ConditionManager $conditionManager
   *   The condition manager.
   * @param \Drupal\rng\EventManagerInterface $event_manager
   *   The RNG event manager.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   The redirect destination service.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   The Config Factory service.
   */
  public function __construct(ActionManager $actionManager, ConditionManager $conditionManager, EventManagerInterface $event_manager, RedirectDestinationInterface $redirect_destination, ConfigFactory $configFactory) {
    $this->actionManager = $actionManager;
    $this->conditionManager = $conditionManager;
    $this->eventManager = $event_manager;
    $this->redirectDestination = $redirect_destination;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.action'),
      $container->get('plugin.manager.condition'),
      $container->get('rng.event_manager'),
      $container->get('redirect.destination'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_rng_event_commerce';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $rng_event = NULL) {
    $event = clone $rng_event;
    $this->event = $event;

    // :TODO: this is crude, find a better way.
    $event_type_id = $event->getEntityTypeId() . '.' . $event->getType();

    $form['event_type_id'] = [
      '#type' => 'hidden',
      '#value' => $event_type_id,
    ];

    $config = $this->configFactory->getEditable('commerce_rng.settings');
    $default_values = unserialize($config->get($event_type_id));
    if ($config->get($event_type_id . ':integrate')) {
      $header = [
        'product_variation_type' => t('Product Variation Type'),
        'registration_type' => t('Registration Type'),
        'registration_groups' => t('Registration Groups'),
      ];

      $form['table'] = [
        '#type' => 'table',
        '#header' => $header,
      ];

      // Load the registration types for this event.
      $options_registration_types = [0 => 'None'];
      $registration_types = $event->get('rng_registration_type')->referencedEntities();
      foreach ($registration_types as $registration_type) {
        $options_registration_types[$registration_type->id()] = $registration_type->label();
      }

      // Load the registration_groups for this event
      // :TODO: this is garbage, filter it at the query level.
      $options_registration_groups = [];
      $registration_groups = Group::loadMultiple();
      foreach ($registration_groups as $registration_group) {
        if ($event->id() == $registration_group->getEvent()->id()) {
          $options_registration_groups[$registration_group->id()] = $registration_group->label();
        }
      }

      // Build the table with options.
      $product_variation_types = ProductVariationType::loadMultiple();
      foreach ($product_variation_types as $product_variation_type) {
        $id = $product_variation_type->id();

        $form['table'][$id]['product_variation_type'] = ['#plain_text' => $product_variation_type->label()];

        $form['table'][$id]['registration_type'] = [
          '#type' => 'select',
          '#options' => $options_registration_types,
          '#default_value' => isset($default_values[$id]) ? $default_values[$id]['registration_type'] : 0,
        ];

        $form['table'][$id]['registration_groups'] = [
          '#type' => 'checkboxes',
          '#options' => $options_registration_groups,
          '#default_value' => isset($default_values[$id]) ? $default_values[$id]['registration_groups'] : [],
        ];
      }

      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => t('Save changes'),
      ];

    }
    else {
      // Put a link in there.
      $form['notice'] = ['#markup' => t('This event type has not been configured to allow commerce integration.')];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $event_type_id = $form_state->getValue('event_type_id');
    $values = serialize($form_state->getValue('table'));

    $config = $this->configFactory->getEditable('commerce_rng.settings');
    $config->set($event_type_id, $values)->save();
  }

}
