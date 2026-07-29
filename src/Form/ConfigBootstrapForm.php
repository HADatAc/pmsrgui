<?php

namespace Drupal\pmsr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * PMSR Config Bootstrap Form.
 *
 * Provides bootstrap functionality for PMSR configuration and ontology ingestion.
 */
class ConfigBootstrapForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pmsr_config_bootstrap_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'pmsr/bootstrap';

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<div class="pmsr-bootstrap-intro">
        <h2>' . $this->t('PMSR Configuration Bootstrap') . '</h2>
        <p>' . $this->t('This tool will bootstrap your PMSR installation by:') . '</p>
        <ul>
          <li>' . $this->t('Resetting PMSR bootstrap caches/state (session filters and ontology backup/version snapshots)') . '</li>
          <li>' . $this->t('Verifying the triplestore is empty (automatic check)') . '</li>
          <li>' . $this->t('Configuring the repository settings in the API') . '</li>
          <li>' . $this->t('Updating local Drupal configuration') . '</li>
        </ul>
        <p class="warning"><strong>' . $this->t('Automatic Safety Check:') . '</strong> ' . 
        $this->t('The system will automatically verify that the triplestore is empty before proceeding. The 3 default repository metadata triples created by hascoapi on first startup are acceptable. If any additional data is found, the bootstrap will be aborted immediately to prevent data loss.') . '</p>
        <p class="info"><strong>' . $this->t('Next Step:') . '</strong> ' . 
        $this->t('After bootstrap completes, use "Ingest PMSR Ontologies" to load the pmsr, ncit, and uberon ontologies.') . '</p>
      </div>',
    ];

    $form['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret Key'),
      '#description' => $this->t('Enter the secret key to proceed with bootstrap. Hint: case-sensitive, no spaces.'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => $this->t('Enter secret key'),
        'autocomplete' => 'off',
      ],
    ];

    $form['buttons_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bootstrap-buttons']],
    ];

    $form['buttons_container']['bootstrap_localhost'] = [
      '#type' => 'submit',
      '#value' => $this->t('Bootstrap Localhost'),
      '#name' => 'bootstrap_localhost',
      '#attributes' => [
        'class' => ['button', 'button--primary', 'bootstrap-btn'],
      ],
    ];

    $form['buttons_container']['bootstrap_cloud'] = [
      '#type' => 'submit',
      '#value' => $this->t('Bootstrap Cloud'),
      '#name' => 'bootstrap_cloud',
      '#disabled' => TRUE,
      '#attributes' => [
        'class' => ['button', 'bootstrap-btn'],
        'title' => $this->t('Cloud bootstrap will be available in a future update'),
      ],
    ];

    $form['progress_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'bootstrap-progress-container',
        'class' => ['bootstrap-progress'],
        'style' => 'display: none;',
      ],
    ];

    $form['progress_container']['progress'] = [
      '#type' => 'markup',
      '#markup' => '<div id="bootstrap-progress"></div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $secret_key = trim($form_state->getValue('secret_key'));
    
    if ($secret_key !== 'itisnotamistake') {
      $form_state->setErrorByName('secret_key', $this->t('Invalid secret key. The correct key is case-sensitive and must be entered exactly.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'bootstrap_localhost') {
      // Redirect to AJAX controller for localhost bootstrap
      $form_state->setRedirect('pmsr.bootstrap_localhost_page');
    }
    elseif ($button_name === 'bootstrap_cloud') {
      \Drupal::messenger()->addWarning($this->t('Cloud bootstrap is not yet implemented.'));
    }
  }

}
