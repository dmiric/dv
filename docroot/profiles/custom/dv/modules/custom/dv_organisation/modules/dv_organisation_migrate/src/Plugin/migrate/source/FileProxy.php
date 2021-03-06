<?php

namespace Drupal\dv_organisation_migrate\Plugin\migrate\source;

use Drupal\migrate_plus\Plugin\migrate\source\Url;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Plugin\migrate_plus\data_fetcher\Http;
use Drupal\migrate\MigrateException;

/**
 * Retrieve data from a local path for migration.
 *
 * @MigrateSource(
 *   id = "file_proxy"
 * )
 */
class FileProxy extends Url {

  /**
   * The XMLReader we are encapsulating.
   *
   * @var \XMLReader
   */
  protected $reader;

  /**
   * Array of the element names from the query.
   *
   * 0-based from the first (root) element. For example, '//file/article' would
   * be stored as [0 => 'file', 1 => 'article'].
   *
   * @var array
   */
  protected $elementsToMatch = [];

  /**
   * An optional xpath predicate.
   *
   * Restricts the matching elements based on values in their children. Parsed
   * from the element query at construct time.
   *
   * @var string
   */
  protected $xpathPredicate = NULL;

  /**
   * Array representing the path to the current element as we traverse the XML.
   *
   * For example, if in an XML string like '<file><article>...</article></file>'
   * we are positioned within the article element, currentPath will be
   * [0 => 'file', 1 => 'article'].
   *
   * @var array
   */
  protected $currentPath = [];

  /**
   * Retains all elements with a given name to support extraction from parents.
   *
   * This is a hack to support field extraction of values in parents
   * of the 'context node' - ie, if $this->fields() has something like '..\nid'.
   * Since we are using a streaming xml processor, it is too late to snoop
   * around parent elements again once we've located an element of interest. So,
   * grab elements with matching names and their depths, and refer back to it
   * when building the source row.
   *
   * @var array
   */
  protected $parentXpathCache = [];

  /**
   * Hash of the element names that should be captured into $parentXpathCache.
   *
   * @var array
   */
  protected $parentElementsOfInterest = [];

  /**
   * Element name matching mode.
   *
   * When matching element names, whether to compare to the namespace-prefixed
   * name, or the local name.
   *
   * @var bool
   */
  protected $prefixedName = FALSE;

  protected $dataFetcher;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);

    $this->dataFetcher = new Http($configuration, $plugin_id, $plugin_definition);

    if( !isset($configuration['preprocess']) ) {
      $configuration['preprocess'] = FALSE;
    }

    $urls = $this->fileProxy($configuration['preprocess']);

    $this->sourceUrls = $urls;

    $this->configuration['urls'] = $urls;

    $this->reader = new \XMLReader();

    // Suppress errors during parsing, so we can pick them up after.
    libxml_use_internal_errors(TRUE);

    // Parse the element query. First capture group is the element path, second
    // (if present) is the attribute.
    preg_match_all('|^/([^\[]+)\[?(.*?)]?$|', $configuration['item_selector'], $matches);
    $element_path = $matches[1][0];
    $this->elementsToMatch = explode('/', $element_path);
    $predicate = $matches[2][0];
    if ($predicate) {
      $this->xpathPredicate = $predicate;
    }

    // If the element path contains any colons, it must be specifying
    // namespaces, so we need to compare using the prefixed element
    // name in next().
    if (strpos($element_path, ':')) {
      $this->prefixedName = TRUE;
    }

    foreach ($this->fieldSelectors() as $field_name => $xpath) {
      $prefix = substr($xpath, 0, 3);
      if ($prefix === '../') {
        $this->parentElementsOfInterest[] = str_replace('../', '', $xpath);
      }
      elseif ($prefix === '..\\') {
        $this->parentElementsOfInterest[] = str_replace('..\\', '', $xpath);
      }
    }

  }

  public function fileProxy($preProcess = FALSE) {

    $urls = [];

    foreach ($this->sourceUrls as $key => $url ) {

      $body = $this->dataFetcher->getResponseContent($url)->getContents();

      if($preProcess) {
        $body = $this->preProcessBody($body);
      }

      $file = file_save_data($body);
      $file->setTemporary();
      $file->save();

      $uri = $file->getFileUri();

      $new_url = file_create_url($uri);

      $urls[] = $new_url;

    }

    return $urls;

  }

  public function preProcessBody($body) {
    $body = str_replace('<NositeljFunkcije>', '<PERSON><NositeljFunkcije>', $body);
    $body = str_replace('</Funkcija>', '</Funkcija></PERSON>', $body);

    return $body;
  }



  /**
   * Builds a \SimpleXmlElement rooted at the iterator's current location.
   *
   * The resulting SimpleXmlElement also contains any child nodes of the current
   * element.
   *
   * @return \SimpleXmlElement|false
   *   A \SimpleXmlElement when the document is parseable, or false if a
   *   parsing error occurred.
   *
   * @throws MigrateException
   */
  protected function getSimpleXml() {
    $node = $this->reader->expand();
    if ($node) {
      // We must associate the DOMNode with a DOMDocument to be able to import
      // it into SimpleXML. Despite appearances, this is almost twice as fast as
      // simplexml_load_string($this->readOuterXML());
      $dom = new \DOMDocument();
      $node = $dom->importNode($node, TRUE);
      $dom->appendChild($node);
      $sxml_elem = simplexml_import_dom($node);
      $this->registerNamespaces($sxml_elem);
      return $sxml_elem;
    }
    else {
      foreach (libxml_get_errors() as $error) {
        $error_string = self::parseLibXmlError($error);
        throw new MigrateException($error_string);
      }
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function fetchNextRow() {
    $target_element = NULL;

    // Loop over each node in the XML file, looking for elements at a path
    // matching the input query string (represented in $this->elementsToMatch).
    while ($this->reader->read()) {
      if ($this->reader->nodeType == \XMLReader::ELEMENT) {
        if ($this->prefixedName) {
          $this->currentPath[$this->reader->depth] = $this->reader->name;
          if (array_key_exists($this->reader->name, $this->parentElementsOfInterest)) {
            $this->parentXpathCache[$this->reader->depth][$this->reader->name][] = $this->getSimpleXml();
          }
        }
        else {
          $this->currentPath[$this->reader->depth] = $this->reader->localName;
          if (array_key_exists($this->reader->localName, $this->parentElementsOfInterest)) {
            $this->parentXpathCache[$this->reader->depth][$this->reader->name][] = $this->getSimpleXml();
          }
        }
        if ($this->currentPath == $this->elementsToMatch) {
          // We're positioned to the right element path - build the SimpleXML
          // object to enable proper xpath predicate evaluation.
          $target_element = $this->getSimpleXml();
          if ($target_element !== FALSE) {
            if (empty($this->xpathPredicate) || $this->predicateMatches($target_element)) {
              break;
            }
          }
        }
      }
      elseif ($this->reader->nodeType == \XMLReader::END_ELEMENT) {
        // Remove this element and any deeper ones from the current path.
        foreach ($this->currentPath as $depth => $name) {
          if ($depth >= $this->reader->depth) {
            unset($this->currentPath[$depth]);
          }
        }
        foreach ($this->parentXpathCache as $depth => $elements) {
          if ($depth > $this->reader->depth) {
            unset($this->parentXpathCache[$depth]);
          }
        }
      }
    }

    // If we've found the desired element, populate the currentItem and
    // currentId with its data.
    if ($target_element !== FALSE && !is_null($target_element)) {
      foreach ($this->fieldSelectors() as $field_name => $xpath) {
        foreach ($target_element->xpath($xpath) as $value) {
          $this->currentItem[$field_name][] = (string) $value;
        }
      }
      // Reduce single-value results to scalars.
      foreach ($this->currentItem as $field_name => $values) {
        if (count($values) == 1) {
          $this->currentItem[$field_name] = reset($values);
        }
      }
    }
  }

  /**
   * Tests whether the iterator's xpath predicate matches the provided element.
   *
   * Has some limitations esp. in that it is easy to write predicates that
   * reference things outside this SimpleXmlElement's tree, but "simpler"
   * predicates should work as expected.
   *
   * @param \SimpleXMLElement $elem
   *   The element to test.
   *
   * @return bool
   *   True if the element matches the predicate, false if not.
   */
  protected function predicateMatches(\SimpleXMLElement $elem) {
    return !empty($elem->xpath('/*[' . $this->xpathPredicate . ']'));
  }

  /**
   * Gets an ancestor SimpleXMLElement, if the element name was registered.
   *
   * Gets the SimpleXMLElement some number of levels above the iterator
   * having the given name, but only for element names that this
   * Xml data parser was told to retain for future reference through the
   * constructor's $parent_elements_of_interest.
   *
   * @param int $levels_up
   *   The number of levels back towards the root of the DOM tree to ascend
   *   before searching for the named element.
   * @param string $name
   *   The name of the desired element.
   *
   * @return \SimpleXMLElement|false
   *   The element matching the level and name requirements, or false if it is
   *   not present or was not retained.
   */
  public function getAncestorElements($levels_up, $name) {
    if ($levels_up > 0) {
      $levels_up *= -1;
    }
    $ancestor_depth = $this->reader->depth + $levels_up + 1;
    if ($ancestor_depth < 0) {
      return FALSE;
    }

    if (array_key_exists($ancestor_depth, $this->parentXpathCache) && array_key_exists($name, $this->parentXpathCache[$ancestor_depth])) {
      return $this->parentXpathCache[$ancestor_depth][$name];
    }
    else {
      return FALSE;
    }
  }

  /**
   * Registers the iterator's namespaces to a SimpleXMLElement.
   *
   * @param \SimpleXMLElement $xml
   *   The element to apply namespace registrations to.
   */
  protected function registerNamespaces(\SimpleXMLElement $xml) {
    if (is_array($this->configuration['namespaces'])) {
      foreach ($this->configuration['namespaces'] as $prefix => $ns) {
        $xml->registerXPathNamespace($prefix, $ns);
      }
    }
  }

  /**
   * Parses a LibXMLError to a error message string.
   *
   * @param \LibXMLError $error
   *   Error thrown by the XML.
   *
   * @return string
   *   Error message
   */
  public static function parseLibXmlError(\LibXMLError $error) {
    $error_code_name = 'Unknown Error';
    switch ($error->level) {
      case LIBXML_ERR_WARNING:
        $error_code_name = t('Warning');
        break;

      case LIBXML_ERR_ERROR:
        $error_code_name = t('Error');
        break;

      case LIBXML_ERR_FATAL:
        $error_code_name = t('Fatal Error');
        break;
    }

    return t(
      "@libxmlerrorcodename @libxmlerrorcode: @libxmlerrormessage\n" .
      "Line: @libxmlerrorline\n" .
      "Column: @libxmlerrorcolumn\n" .
      "File: @libxmlerrorfile",
      [
        '@libxmlerrorcodename' => $error_code_name,
        '@libxmlerrorcode' => $error->code,
        '@libxmlerrormessage' => trim($error->message),
        '@libxmlerrorline' => $error->line,
        '@libxmlerrorcolumn' => $error->column,
        '@libxmlerrorfile' => (($error->file)) ? $error->file : NULL,
      ]
    );
  }

}