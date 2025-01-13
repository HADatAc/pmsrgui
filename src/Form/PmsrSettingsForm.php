<?php

namespace Drupal\pmsr\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class PmsrSettingsForm extends ConfigFormBase {
  protected function getEditableConfigNames() {
    return ['pmsr.settings'];
  }

  public function getFormId() {
    return 'pmsr_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('pmsr.settings');

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $config->get('title'),
    ];

    $form['primary_color'] = [
      '#type' => 'color',
      '#title' => $this->t('Main Color'),
      '#default_value' => $config->get('primary_color'),
    ];

    $form['secondary_color'] = [
      '#type' => 'color',
      '#title' => $this->t('Secondary Color'),
      '#default_value' => $config->get('secondary_color'),
    ];

    $form['logo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Logo'),
      '#upload_location' => 'public://pmsr_images/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'],
        'file_validate_image_resolution' => ['150x150', ''],
      ],
      '#default_value' => $config->get('logo'),
      '#description' => $this->t('Image should have 150px x 150px.'),
    ];

    $form['image_1'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Imagem 1'),
      '#upload_location' => 'public://pmsr_images/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'],
        'file_validate_image_resolution' => ['1400x1', ''],
      ],
      '#default_value' => $config->get('image_1'),
      '#description' => $this->t('Image should have at least 1400px wide.'),
    ];

    $form['image_2'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Imagem 2'),
      '#upload_location' => 'public://pmsr_images/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'],
        'file_validate_image_resolution' => ['1400x1', ''],
      ],
      '#default_value' => $config->get('image_2'),
      '#description' => $this->t('Image should have at least 1400px wide.'),
    ];

    $form['image_3'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Imagem 3'),
      '#upload_location' => 'public://pmsr_images/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg'],
        'file_validate_image_resolution' => ['1400x1', ''],
      ],
      '#default_value' => $config->get('image_3'),
      '#description' => $this->t('Image should have at least 1400px wide.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('pmsr.settings')
      ->set('title', $form_state->getValue('title'))
      ->set('logo', $form_state->getValue('logo'))
      ->set('primary_color', $form_state->getValue('primary_color'))
      ->set('secondary_color', $form_state->getValue('secondary_color'))
      ->set('image_1', $form_state->getValue('image_1'))
      ->set('image_2', $form_state->getValue('image_2'))
      ->set('image_3', $form_state->getValue('image_3'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
