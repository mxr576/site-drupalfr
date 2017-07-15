<?php

namespace Drupal\drupalfr_releases;

use SimpleXMLElement;
use Drupal\node\Entity\Node;

/**
 * Functions for parse the xml, return the last stable release ...
 */
class Release {

  /**
   * The XML URL.
   *
   * @var string
   */
  private $xmlUrl = '';

  /**
   * The XML response.
   *
   * @var string
   */
  private $xml = '';

  /**
   * Release constructor.
   */
  public function __construct() {
    $release_config = \Drupal::config('drupalfr_releases.settings');
    $this->xmlUrl = $release_config->get('xml_address');
  }

  /**
   * Return full url to the last published release.
   */
  public function getLastPublished() {
    $query = \Drupal::entityQuery('node');

    $query->condition('status', 1)
      ->condition('type', 'drupal_release')
      ->condition('field_release_version_extra', '', '=')
      ->range(0, 1)
      ->sort('created', 'DESC');

    return $query->execute();
  }

  /**
   * Import drupal release versions.
   */
  public function importWithBatch() {
    $this->getFeed();

    $batch = [
      'title' => t('Importing'),
      'operations' => [
        [
          '\Drupal\drupalfr_releases\Release::importFeed',
          [$this, 5],
        ],
      ],
      'file' => drupal_get_path('module', 'drupalfr_releases') . '/src/Release.php',
    ];

    batch_set($batch);
  }

  /**
   * Get xml feed.
   */
  protected function getFeed() {
    $client = \Drupal::httpClient();
    $response = $client->get($this->xmlUrl);
    $this->xml = $response->getBody()->getContents();
  }

  /**
   * Import Drupal releases.
   */
  public function importWithCron() {
    $this->getFeed();

    // I set fake context for use same method when I load by Cron or Batch.
    $fake_context = [];

    static::importFeed($this, 0, $fake_context);
  }

  /**
   * Function for import drupal release.
   *
   * @param \Drupal\drupalfr_releases\Release $release
   *   A release object.
   * @param int $nb
   *   Number of release to get.
   * @param array $context
   *   Batch context.
   */
  public static function importFeed(Release $release, $nb, array &$context) {
    $xmlElement = new SimpleXMLElement($release->xml);
    $elements = $xmlElement->xpath("//releases/release");

    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = count($elements);
    }

    // Check if we restrict elements.
    $results = $nb > 0 ? array_slice($elements, $context['sandbox']['progress'], 5) : $elements;

    foreach ($results as $result) {
      $context['message'] = t('Import drupal version : @version', ['@version' => (string) $result->version]);
      $context['sandbox']['progress']++;

      // If node exist, we update it.
      $nid = $release->checkNodeExist('drupal_release', $result->name);

      if (empty($nid)) {
        $node = Node::create(['type' => 'drupal_release']);
      }
      else {
        $node = Node::load($nid);
      }

      // Set values of fields.
      $node->set('title', $result->name);
      $node->set('status', TRUE);
      $node->set('created', $result->date);
      $node->set('field_release_version_major', $result->version_major);
      $node->set('field_release_version_minor', $result->version_minor);
      $node->set('field_release_version_patch', $result->version_patch);
      $node->set('field_release_version_extra', $result->version_extra);
      $node->set('field_release_link', $result->release_link);
      $node->set('field_release_file_targz', $result->files->file[0]->url);
      $node->set('field_release_file_zip', $result->files->file[1]->url);
      $node->save();

      \Drupal::logger('drupalfr_releases_import')
        ->notice('Release (' . $result->name . ') was imported/updated');
    }

    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Check if node exist.
   *
   * @param string $type
   *   The type of node to check.
   * @param string $title
   *   The title of the node.
   *
   * @return mixed
   *   The node id if it exists.
   */
  protected function checkNodeExist($type, $title) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', $type)
      ->condition('title', $title)
      ->range(0, 1);

    $result = $query->execute();
    return reset($result);
  }

}
