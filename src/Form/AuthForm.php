<?php

namespace Drupal\hydro_raindrop\Form;

use Adrenth\Raindrop\ApiSettings;
use Adrenth\Raindrop\Client;
use Adrenth\Raindrop\Environment;
use Adrenth\Raindrop\Exception;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hydro_raindrop\TokenStorage\PrivateTempStoreStorage;
use Drupal\user\Entity\User;
use Drupal\User\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class AuthForm.
 */
class AuthForm extends FormBase {

  /**
   * @var Drupal\User\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Constructs a new AuthForm object.
   *
   * @param PrivateTempStoreFactory $temp_store_factory
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory) {
    $this->tempStore = $temp_store_factory->get('hydro_raindrop');
  }
 
  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.private_tempstore')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'hydro-raindrop-auth-form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $user = User::load(\Drupal::currentUser()->id());
    $hydro_raindrop_verified = $user->field_hydro_raindrop_status->value;

    if (!$hydro_raindrop_verified) {
      $form['hydro_raindrop_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('HydroID'),
        '#description' => 'Enter your HydroID, visible in the Hydro mobile app.',
        '#maxlength' => 7,
        '#size' => 7,
        '#weight' => '0',
      ];
    }
    
    $message = $hydro_raindrop_verified ? '6 digit message: ' . $this->ajaxGenerateMessage() : '';
    $form['hydro_raindrop_message'] = [
      '#prefix' => '<div id="hydro-raindrop-message">',
      '#markup' => $message,
      '#suffix' => '</div>',
    ];

    if (!$hydro_raindrop_verified) {
      $form['hydro_raindrop_ajax_register_user'] = [
        '#type' => 'button',
        '#value' => $this->t('Register'),
        '#ajax' => array(
          'callback' => '::ajaxRegisterUser',
          'event' => 'click',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('Registering...'),
          ],
        ),
        '#weight' => '1',
      ];
    }

    $form['hydro_raindrop_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Authenticate'),
      '#attributes' => [
        'disabled' => !$hydro_raindrop_verified
      ],
      '#weight' => '2',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = User::load(\Drupal::currentUser()->id());
    $hydroId = $form_state->getValue('hydro_raindrop_id') ?: $user->field_hydro_raindrop_id->value;
    $message = (int) $this->tempStore->get('hydro_raindrop_message');
    $this->tempStore->set('hydro_raindrop_message', NULL);

    // If the user passes verification...
    if ($this->verifySignature($hydroId, $message)) {
      // Indicate that Raindrop is linked and authenticated.
      $user->set('field_hydro_raindrop_status', TRUE);
      $user->save();

      // Redirect to profile page.
      $form_state->setRedirect('user.page');
    }

    // Otherwise the form will reload with an error from verifySignature.
  }

  /**
   * Asynchronously register a user using the provided Hydro ID.
   *
   * @param array $form
   * @param FormStateInterface $form_state
   *
   * @return AjaxResponse
   */
  public function ajaxRegisterUser(array &$form, FormStateInterface $form_state): AjaxResponse {
    $ajax_response = new AjaxResponse();
    $client = $this->getClient();
    $hydroId = $form_state->getValue('hydro_raindrop_id');

    $this->_lockForm($ajax_response);

    try {
      $client->registerUser($hydroId);

      drupal_set_message(t('HydroID <b><i>@hydroId</i></b> has been successfully registered.', ['@hydroId' => $hydroId]));

      $this->ajaxGenerateMessage($ajax_response);
    }
    catch (Exception\UserAlreadyMappedToApplication $e) {
      drupal_set_message(t('HydroID <b><i>@hydroId</i></b> was already mapped to this application.', ['@hydroId' => $hydroId]), 'warning');

      $client->unregisterUser($hydroId);
      $client->registerUser($hydroId);

      $this->ajaxGenerateMessage($ajax_response);
    }
    catch (Exception\UsernameDoesNotExist $e) {
      drupal_set_message(t('HydroID <b><i>@hydroId</i></b> does not exist.', ['@hydroId' => $hydroId]), 'error');
      $this->_unlockForm($ajax_response);
    }

    $ajax_response->addCommand(new HtmlCommand('.region-highlighted', ['#type' => 'status_messages']));

    return $ajax_response;
  }

  /**
   * Uses the Raindrop developer's API credentials to return a client object.
   *
   * @param Environment $environment
   *
   * @return Client
   */
  protected function getClient(Environment $environment = NULL): Client {
    $config = $this->config('hydro_raindrop.settings');
    $clientId = $config->get('client_id');
    $clientSecret = $config->get('client_secret');
    $environment_class = $config->get('environment');
    $environment = new $environment_class();
    $tokenStorage = new PrivateTempStoreStorage($this->tempStore);
    $applicationId = $config->get('application_id');

    $settings = new ApiSettings(
      $clientId,
      $clientSecret,
      $environment
    );

    return new Client($settings, $tokenStorage, $applicationId);
  }

  /**
   * Attaches the registered HydroID to the user's account.
   *
   * @param string $hydroId
   */
  protected function attachHydroId(string $hydroId) {
    $user = User::load(\Drupal::currentUser()->id());
    $user->set('field_hydro_raindrop_id', $hydroId);
    $user->save();
  }

  /**
   * Generate 6 digit message.
   *
   * @param AjaxResponse $ajax_response
   */
  protected function ajaxGenerateMessage($ajax_response = NULL) {
    $client = $this->getClient();
    
    // Fix for weird bug where the message was generated twice before submission
    if (empty($this->tempStore->get('hydro_raindrop_message'))) {
      $this->tempStore->set('hydro_raindrop_message', $client->generateMessage());
    }

    if (!$ajax_response) {
      return $this->tempStore->get('hydro_raindrop_message');
    } else {
      // Display hydro_raindrop_message.
      $ajax_response->addCommand(
        new HtmlCommand(
          '#hydro-raindrop-message',
          '6 digit message: ' . $this->tempStore->get('hydro_raindrop_message')
        )
      );

      $ajax_response->addCommand(
        new InvokeCommand('#edit-hydro-raindrop-submit', 'attr', ['disabled', FALSE])
      );
    }
  }

  /**
   * Verify Hydro user signature.
   *
   * @param string $hydroId
   * @param integer $message
   *
   * @return bool
   */
  protected function verifySignature(string $hydroId, int $message): bool {
    $client = $this->getClient();
    try {
      $client->verifySignature($hydroId, $message);

      // At this point we can attach the Hydro ID to the user.
      $this->attachHydroId($hydroId);

      drupal_set_message(t('HydroID <b><i>@hydroId</i></b> has been verified.', ['@hydroId' => $hydroId]));

      return TRUE;
    }
    catch (Exception\VerifySignatureFailed $e) {
      drupal_set_message(t('HydroID <b><i>@hydroId</i></b> could not be verified.', ['@hydroId' => $hydroId]), 'error');
    }
    return FALSE;
  }

  /**
   * Prevents user from editing their ID once clicking the Register button and disables button.
   *
   * @param AjaxResponse $ajax_response
   */
  private function _lockForm(AjaxResponse &$ajax_response) {
    $ajax_response->addCommand(
      new InvokeCommand('#edit-hydro-raindrop-id', 'attr', ['readonly', TRUE])
    );

    $ajax_response->addCommand(
      new InvokeCommand('#edit-hydro-raindrop-ajax-register-user', 'attr', ['disabled', TRUE])
    );
  }

  /**
   * Allows a user to edit their ID and re-attempt to register (i.e. in the case of an error).
   *
   * @param AjaxResponse $ajax_response
   */
  private function _unlockForm(AjaxResponse &$ajax_response) {
    $ajax_response->addCommand(
      new InvokeCommand('#edit-hydro-raindrop-id', 'attr', ['readonly', FALSE])
    );

    $ajax_response->addCommand(
      new InvokeCommand('#edit-hydro-raindrop-ajax-register-user', 'attr', ['disabled', FALSE])
    );
  }

}
