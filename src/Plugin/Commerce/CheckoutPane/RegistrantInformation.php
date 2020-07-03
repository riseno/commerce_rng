<?php

namespace Drupal\commerce_rng\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Form;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Url;
use Drupal\rng\EventManagerInterface;
use Drupal\rng\RegistrantFactoryInterface;
use Drupal\rng\Entity\RegistrantInterface;
use Drupal\rng\Entity\RegistrationInterface;
use Drupal\rng\Entity\Registration;
use Drupal\commerce_rng\Form\RegistrantFormHelperInterface;
use Drupal\commerce_rng\RegistrationDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the pane to collect registrant information.
 *
 * @todo add validation: don't allow submit if there are zero registrants.
 *
 * @CommerceCheckoutPane(
 *   id = "registrant_information",
 *   label = @Translation("Attendee information"),
 *   default_step = "event_registration",
 *   wrapper_element = "container",
 * )
 */
class RegistrantInformation extends CheckoutPaneBase implements IsPaneCompleteInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * The redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $destination;

  /**
   * The event manager.
   *
   * @var \Drupal\rng\EventManagerInterface
   */
  protected $eventManager;

  /**
   * The factory for creating a registrant entities.
   *
   * @var \Drupal\rng\RegistrantFactoryInterface
   */
  protected $registrantFactory;

  /**
   * Helper class for generating registrant forms.
   *
   * @var \Drupal\commerce_rng\Form\RegistrantFormHelperInterface
   */
  protected $registrantFormHelper;

  /**
   * Object for working with registration data.
   *
   * @var \Drupal\commerce_rng\RegistrationDataInterface
   */
  protected $registrationData;

  /**
   * @var \Drupal\Core\Entity\EntityFormBuilderInterface
   */
  protected $entityFormBuilder;

  /**
   * Constructs a new RegistrantInformation object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface $checkout_flow
   *   The parent checkout flow.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   *   The module handler.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $destination
   *   The redirect destination service.
   * @param \Drupal\rng\EventManagerInterface $event_manager
   *   The RNG event manager.
   * @param \Drupal\rng\RegistrantFactoryInterface $registrant_factory
   *   The factory for creating a registrant entities.
   * @param \Drupal\commerce_rng\Form\RegistrantFormHelperInterface $registrant_form_helper
   *   Helper class for generating registrant forms.
   * @param \Drupal\commerce_rng\RegistrationDataInterface $registration_data
   *   Object for working with registration data.
   * @param \Drupal\Core\Entity\EntityFormBuilderInterface $entity_form_builder
   *   Entity FormBuilder service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    CheckoutFlowInterface $checkout_flow,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandler $module_handler,
    RedirectDestinationInterface $destination,
    EventManagerInterface $event_manager,
    RegistrantFactoryInterface $registrant_factory,
    RegistrantFormHelperInterface $registrant_form_helper,
    RegistrationDataInterface $registration_data,
    EntityFormBuilderInterface $entity_form_builder
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow, $entity_type_manager);

    $this->moduleHandler = $module_handler;
    $this->destination = $destination;
    $this->eventManager = $event_manager;
    $this->registrantFactory = $registrant_factory;
    $this->registrantFormHelper = $registrant_form_helper;
    $this->registrationData = $registration_data;
    $this->entityFormBuilder = $entity_form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('redirect.destination'),
      $container->get('rng.event_manager'),
      $container->get('rng.registrant.factory'),
      $container->get('commerce_rng.registrant_form'),
      $container->get('commerce_rng.registration_data'),
      $container->get('entity.form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    // The order must contain at least one event product entity.
    foreach ($this->order->getItems() as $order_item) {
      if ($this->orderItemGetEvent($order_item)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isComplete() {
    foreach ($this->order->getItems() as $order_item) {
      $product = $this->orderItemGetEvent($order_item);
      if (!$product) {
        // Not an event.
        continue;
      }

      $order_item_id = $order_item->id();

      // Check for an existing registration on the order item.
      /** @var \Drupal\rng\Entity\RegistrationInterface|null $registration */
      $registration = $this->registrationData->getRegistrationByOrderItemId($order_item_id);
      if (!$registration) {
        // A certain event item does not contain a registration yet. Information
        // is not complete.
        return FALSE;
      }

      // Count registrants. Check if there are enough registrants.
      if (count($registration->getRegistrantIds()) < 1) {
        // There exists a registration with zero registrants. Information is not
        // complete.
        return FALSE;
      }
    }

    // In all other cases, the information seems to be complete.
    return TRUE;
  }

  /**
   * Returns the order item's product if the product is a RNG event.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   The order item to check for.
   *
   * @return \Drupal\commerce_product\Entity\ProductInterface|null
   *   The product entity if it is an event, or null.
   */
  protected function orderItemGetEvent(OrderItemInterface $order_item) {
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity instanceof ProductVariationInterface) {
      $product = $purchased_entity->getProduct();
      if ($product && $this->eventManager->isEvent($product)) {
        return $product;
      }
    }
  }

  /**
   * Returns the registrants from the given registration.
   *
   * @param \Drupal\rng\RegistrationInterface $registration
   *   A registration entity.
   *
   * @return \Drupal\rng\Entity\RegistrantInterface[]
   *   An array of registrant entities.
   */
  protected function getRegistrants(RegistrationInterface $registration) {
    return $registration->getRegistrants();
  }

  /**
   * Get the active registrant to load a form for.
   *
   * @param array $form
   *   The form structure to fill in.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete state of the checkout form.
   * @param \Drupal\rng\Entity\RegistrationInterface $registration
   *   A registration entity.
   *
   * @return \Drupal\rng\Entity\RegistrantInterface|null
   *   The registrant to load a form for.
   */
  protected function getActiveRegistrant(array $form, FormStateInterface $form_state, RegistrationInterface $registration) {
    // First try to load it from form state.
    $registrant = $form_state->get('registrant__entity');

    // Secondly, pick the first one, if available and if there is only one.
    if (!$registrant) {
      $registrants = $this->getRegistrants($registration);

      // If there is no registrant, create a new one.
      if (count($registrants) === 0) {
        $registrant = $this->createRegistrant($registration);
      }
    }

    return $registrant;
  }

  /**
   * Creates a registration for the given order item.
   *
   * @param \Drupal\Core\Entity\EntityInterface $event
   *   The event to create a registration entity for.
   *
   * @return \Drupal\rng\Entity\RegistrationInterface
   *   A registration instance.
   *
   * @todo fails if event is not configured.
   */
  protected function createRegistration(EntityInterface $event) {
    $registration_types = $this->eventManager->getMeta($event)->getRegistrationTypes();
    if (count($registration_types) > 1) {
      throw new \Exception('Multiple registration types not supported by UKKB Study.');
    }
    if (count($registration_types) === 0) {
      throw new \Exception('No registration types found.');
    }

    $registration_type = reset($registration_types);

    $registration = Registration::create([
      'type' => $registration_type->id(),
    ]);
    $registration->setEvent($event);

    return $registration;
  }

  /**
   * Creates a new registrant.
   *
   * @param \Drupal\rng\Entity\RegistrationInterface $registration
   *   A registration entity.
   *
   * @return \Drupal\rng\Entity\RegistrantInterface
   *   The created registrant.
   */
  protected function createRegistrant(RegistrationInterface $registration) {
    $registrant = $this->registrantFactory->createRegistrant([
      'event' => $registration->getEvent(),
    ]);
    $registrant->setRegistration($registration);

    return $registrant;
  }

  /**
   * Deletes a registrant.
   *
   * @param \Drupal\rng\Entity\RegistrantInterface $registrant
   *   The registrant to delete.
   */
  protected function deleteRegistrant(RegistrantInterface $registrant) {
    $registrant->delete();
    // @todo remove person as well in certain cases.
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary() {
    $list = $this->registrationData->buildRegistrantLists($this->order);
    foreach ($this->order->getItems() as $order_item) {
      $order_item_id = $order_item->id();
      if (!isset($list[$order_item_id])) {
        continue;
      }
      /** @var \Drupal\rng\Entity\Registration|null $registration */
      $registration = $this->registrationData->getRegistrationByOrderItemId($order_item_id);
      $list[$order_item_id]['#type'] = 'item';
      $list[$order_item_id]['#title'] = $order_item->label(). ' - ' . $registration->getDateString();

      unset($list[$order_item_id]['registrants']['#title']);
    }

    return $list;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    foreach ($this->order->getItems() as $order_item) {
      $product = $this->orderItemGetEvent($order_item);
      if (!$product) {
        // Not an event.
        continue;
      }

      $order_item_id = $order_item->id();

      // Check for an existing registration on the order item.
      /** @var \Drupal\rng\Entity\Registration|null $registration */
      $registration = $this->registrationData->getRegistrationByOrderItemId($order_item_id);
      if (!$registration) {
        // Create a new registration.
        $registration = $this->createRegistration($product);
        $registration->field_order_item = $order_item_id;
        $registration->setRegistrantQty($order_item->getQuantity());
        $registration->setConfirmed(FALSE);
        $registration->save();
      }

      $pane_form[$order_item_id] = [
        '#parents' => array_merge($pane_form['#parents'], [$order_item_id]),
      ];
      $pane_form[$order_item_id] = $this->buildRegistrationForm($pane_form[$order_item_id], $form_state, $registration);
    }

    return $pane_form;
  }


  /**
   * Builds a form for a single event.
   *
   * @param array $form
   *   The form structure to fill in.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete state of the checkout form.
   * @param \Drupal\rng\Entity\RegistrationInterface $registration
   *   A registration entity.
   *
   * @return array
   *   A form for creating or editing a registrant.
   */
  protected function buildRegistrationForm(array &$form, FormStateInterface $form_state, RegistrationInterface $registration) {
    // Set #parents to 'top-level' by default.
    $form += ['#parents' => []];

    $form['#type'] = 'fieldset';
    $form['#title'] = $registration->getEvent()->label();

    $eventDates = $registration->getDateString();
    if (!empty($eventDates)) {
      $form['date'] = [
        '#type' => 'label',
        '#title' => $eventDates,
      ];
    }

    $event = $registration->getEvent();
    $event_meta = $this->eventManager->getMeta($event);
    $registrants = $this->getRegistrants($registration);
    if (count($registrants)) {
      // Show registrants in a table.
      $form['people'] = [
        '#type' => 'div',
        '#weight' => 10,
        '#open' => TRUE,
        '#tree' => TRUE,
      ];
      $event_type = $event_meta->getEventType();
      $form['people']['registrants'] = [
        '#type' => 'registrants',
        '#event' => $event,
        '#default_value' => $registrants,
        '#allow_creation' => $event_meta->getCreatableIdentityTypes(),
        '#allow_reference' => $event_meta->getIdentityTypes(),
        '#registration' => $registration,
        '#form_modes' => $event_type->getIdentityTypeEntityFormModes(),
        '#tree' => TRUE,
      ];
      $form['registrants_before'] = [
        '#type' => 'value',
        '#value' => $registrants,
      ];
    }

    $form['actions'] = [
      '#weight' => 50,
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'actions',
        ],
      ],
    ];

    /*
    $form['actions']['add'] = [
      '#title' => $this->t('Add registrant'),
      '#type' => 'link',
      '#url' => Url::fromRoute('commerce_rng.customer_registrant_form.add', [
        'commerce_order' => $this->order->id(),
        'registration' => $registration->id(),
        'js' => 'nojs',
      ]),
      '#options' => [
        'attributes' => [
          'class' => [
            'use-ajax',
            'registrant-button',
            'registrant-add-button',
          ],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 1000,
          ]),
        ],
        'query' => $this->destination->getAsArray(),
      ],
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
      '#theme_wrappers' => [
        'container' => [
          '#attributes' => [
            'class' => ['registrant-button-wrapper', 'registrant-add-button-wrapper'],
          ],
        ],
      ],
    ]; */

    return $form;
  }

  /**
   * Builds a table of registrants.
   *
   * @param array $element
   *   The form element to add the registrants table to.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\rng\Entity\Registrant[] $registrants
   *   The registrants to display in the table.
   *
   * @return array
   *   The form element.
   */
  protected function buildRegistrantTable(array $element, FormStateInterface $form_state, array $registrants) {
    // Set #parents to 'top-level' by default.
    $element += ['#parents' => []];

    $element['registrants'] = [
      '#type' => 'registrants',
      '#default_value' => $registrants,
      '#tree' => TRUE,
      '#registration'
    ];

    $count = 0;
    foreach ($registrants as $i => $registrant) {
      $count++;
      $row = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['registrant'],
        ],
      ];

      if ($registrant->getIdentity()) {

        $row['registrant'] = [
          '#type' => 'item',
          '#title' => $this->t('Attendee @number', [
            '@number' => $count,
          ]),
          '#markup' => $registrant->label(),
        ];

        $row['actions'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['actions'],
          ],
          'edit' => [
            // Needs a name else the submission handlers think all buttons are the
            // last button.
            '#name' => 'ajax-submit-' . implode('-', $element['#parents']) . '-' . $i . '-edit',
            '#title' => $this->t('Edit'),
            '#type' => 'link',
            '#url' => Url::fromRoute('commerce_rng.customer_registrant_form.edit', [
              'commerce_order' => $this->order->id(),
              'registration' => $registrant->getRegistration()->id(),
              'registrant' => $registrant->id(),
              'js' => 'nojs',
            ]),
            '#options' => [
              'attributes' => [
                'class' => [
                  'use-ajax',
                  'registrant-button',
                  'registrant-edit-button',
                ],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => Json::encode([
                  'width' => 1000,
                ]),
              ],
              'query' => $this->destination->getAsArray(),
            ],
            '#attached' => ['library' => ['core/drupal.dialog.ajax']],
          ],
          'remove' => [
            // Needs a name else the submission handlers think all buttons are the
            // last button.
            '#name' => 'ajax-submit-' . implode('-', $element['#parents']) . '-' . $i . '-remove',
            '#title' => $this->t('Remove'),
            '#type' => 'link',
            '#url' => Url::fromRoute('commerce_rng.customer_registrant_form.delete', [
              'commerce_order' => $this->order->id(),
              'registration' => $registrant->getRegistration()->id(),
              'registrant' => $registrant->id(),
              'js' => 'nojs',
            ]),
            '#options' => [
              'attributes' => [
                'class' => [
                  'use-ajax',
                  'registrant-button',
                  'registrant-remove-button',
                ],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => Json::encode([
                  'width' => 1000,
                ]),
              ],
              'query' => $this->destination->getAsArray(),
            ],
            '#attached' => ['library' => ['core/drupal.dialog.ajax']],
          ],
        ];
      }
      else {
        $registrant_form = $this->entityFormBuilder->getForm($registrant, 'compact');


        $row['registrant'] = $registrant_form;
      }

      $element['registrants'][$i] = $row;
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    foreach ($this->order->getItems() as $order_item) {
      $product = $this->orderItemGetEvent($order_item);
      if (!$product) {
        // Not an event.
        continue;
      }

      $order_item_id = $order_item->id();

      // Check for enough registrants on the order item.
      /** @var \Drupal\rng\Entity\Registration|null $registration */
      $registration = $this->registrationData->getRegistrationByOrderItemId($order_item_id);
      /** @var \Drupal\rng\EventMetaInterface $meta */
      $meta = $this->eventManager->getMeta($product);
      $maximum = $meta->getRegistrantsMaximum();

      if (!$registration) {
        // A certain event item does not contain a registration yet or has zero
        // registrants.
        $form_state->setError($pane_form, 'There are not enough registrants for %title. There must be at least 1 registrant.');
      }
      elseif ($maximum > 0 && count($registration->getRegistrantIds()) > $maximum) {
        $form_state->setError($pane_form, $this->formatPlural($maximum, 'There are too many registrants for %title. There must be at most 1 registrant.', 'There are too many registrants for %title. There must be at most @maximum registrants.', [
          '%title' => $order_item->getTitle(),
          '@maximum' => $maximum,
        ]));
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    // Need to save actual registrants on form.
    foreach ($form_state->getValue($pane_form['#parents']) as $reg_id=>$registration) {
      foreach ($registration['people']['registrants'] as $registrant) {
        $registrant->save();
      }
    }
  }

}
