<?php

require_once '../vendor/autoload.php';

// enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// remove time limit
set_time_limit(0);

// configure memcached
$memcached = new Memcached();
$memcached->addServer('petitionscache', 11211);

// configure dom
$dom = new PHPHtmlParser\Dom();
$dom->setOptions([
  'whitespaceTextNode' => false,
]);

// config
$config = [
  'chamberBaseUrl' => 'http://chamber.lu',
  'chamberToken' => 'nZBBDoIwEEXPwgk6bXGAJVBtC0ERKWA3hhUhUXRhPL-GuNBNJc7uJ-_9TD6xpCN26h_j0N_H69SfX_lo8VTSIlRpCAVQEULM6wbToqYASNoZoHoVKw1MwQYRdKlEhLnPc0mJXeLD18WQVCzhAHLH_vE_m5b5DsC661tiZ8S1wAwIJppK-wxksPeBZessMIctlxG-AdcGv764XYwxHYx68LwnDjWFBg!!/p0/IZ7_P1M8HC80M01D80A3TV6CMT10G5=CZ6_P1M8HC80M01D80A3TV6CMT1006',
  'petitionsPageUrl' => 'http://chamber.lu/wps/portal/public/Accueil/TravailALaChambre/Petitions/RoleDesPetitions/!ut/p/z1/%s=MEePetition!listPetitionRole==/?pageNumber=%d',
  'petitionUrl' => 'http://chamber.lu/wps/portal/public/Accueil/TravailALaChambre/Petitions/RoleDesPetitions?action=doPetitionDetail&id=%d',
  'petitionSignaturePageUrl' => 'http://chamber.lu/wps/portal/public/Accueil/TravailALaChambre/Petitions/RoleDesPetitions/!ut/p/z1/%s=MEpetition_id!%d=ePetition!PetitionSignatureList==/?sortDirection=ASC&pageNumber=%d',
  'postCodeCantonFilename' => __DIR__ . '/../data/post-code-canton/post-code-canton.json',
  'exportJsonFilename' => __DIR__ . '/../data/petitions.json',
  'exportCSVFilename' => __DIR__ . '/../data/petitions.csv',
  'singleExportJsonFilename' => __DIR__ . '/../data/petition-%s-%d.json',
];

if (isset($_GET['id'])) {

  $petition = fetchPetitionById($_GET['id'], true);

  // export as json
  header('Content-Type: application/json');
  $json = json_encode($petition);
  $filename = sprintf(
    $config['singleExportJsonFilename'],
    $petition['type'],
    $petition['number']
  );
  file_put_contents($filename, $json);
  die($json);

} else {

  // fetch petitions. as simple as that.
  $petitions = fetchPetitions();

  // export as csv
  $columns = [
    'id'                    => 'Id',
    'reclassifiedId'        => 'Reclassified Id',
    'number'                => 'Number',
    'type'                  => 'Type',
    'title'                 => 'Title',
    'author'                => 'Author',
    'authorRole'            => 'Author Role',
    'authorOrganisation'    => 'Author Organisation',
    'status'                => 'Status',
    'submissionDate'        => 'Submission Date',
    'signatures'            => 'Signatures',
    'signaturesElectronic'  => 'Signatures (electronic)',
    'signaturesPaper'       => 'Signatures (paper)',
  ];

  $exportCSVFile = fopen($config['exportCSVFilename'], 'w');

  // header row
  fputcsv($exportCSVFile, array_values($columns));

  // data rows
  foreach ($petitions as $petition) {
    $rowValues = [];
    foreach ($columns as $field => $label) {
      $rowValues[$field] = $petition[$field];
    }
    fputcsv($exportCSVFile, $rowValues);
  }

  fclose($exportCSVFile);

  // export as json
  header('Content-Type: application/json');
  $json = json_encode($petitions);
  file_put_contents($config['exportJsonFilename'], $json);
  die($json);
}

function fetchPetitions()
{
  global $config;

  $petitions = [];
  $page = 0;

  do {
    $page ++;
    trigger_error(sprintf(
      'Fetching petition page %d...',
      $page),
    E_USER_NOTICE);

    // retrieve next petitions page
    $url = sprintf(
      $config['petitionsPageUrl'],
      $config['chamberToken'],
      $page);

    $document = fetchDocument($url);

    // collect petition urls
    $petitionRows = $document->find('#petitionList > table tr');
    $petitionPaths = [];
    for ($i = 0; $i + 2 < count($petitionRows); $i += 3) {
      $url = $petitionRows[$i + 1]->find('a')[0]->href;
      $url = str_replace('&amp;', '&', $url);
      array_push($petitionPaths, $url);
    }

    unset($document);

    // create entry for each petition url
    foreach ($petitionPaths as $petitionPath) {
      // TODO: correctly resolve path
      $petitionUrl = $config['chamberBaseUrl'] . $petitionPath;

      $petition = fetchPetitionWithUrl($petitionUrl);
      array_push($petitions, $petition);

      // check for reclassified petition
      if ($petition['reclassifiedId'] !== null) {
        $reclassifiedPetition = fetchPetitionById($petition['reclassifiedId']);
        array_push($petitions, $reclassifiedPetition);
      }
    }

    // continue as long as there are entries
  } while (count($petitionPaths) > 0);

  return $petitions;
}

function fetchPetitionById($id, $fetchDetail = false) {
  global $config;
  $url = sprintf($config['petitionUrl'], $id);
  return fetchPetitionWithUrl($url, $fetchDetail);
}

function fetchPetitionWithUrl($url, $fetchDetail = false)
{
  global $config;

  // retrieve id
  $id = getPetitionIdFromUrl($url);
  if ($id === null) {
    trigger_error(sprintf(
      'Unvalid petition url %s.',
      $url
    ), E_USER_WARNING);
    return null;
  }

  trigger_error(sprintf(
    'Fetching petition id %d...',
    $id
  ), E_USER_NOTICE);

  // fetch petition detail page
  $document = fetchDocument($url);
  $detailNode = $document->find('#PRINT_EPETITION_DETAIL')[0];

  // parse the document to retrieve raw facts
  $raw = [];

  // parse global header node
  $propertyValueNodes = $detailNode->find('.global_header .property_value');
  foreach ($propertyValueNodes as $propertyValueNode) {
    $value = trim($propertyValueNode->text);
    $name = null;

    try {
      // try to get previous sibling
      $propertyNameNode = $propertyValueNode->previousSibling();
      if ($propertyNameNode && $propertyNameNode->class === 'property_name') {
        $name = substr(mb_strtolower(trim($propertyNameNode->text)), 0, -1);
      }
    } catch (Exception $e) {
      // the status property is known to not having a property_name sibling
      $name = 'status';
    }

    $raw[$name] = $value;
  }

  // parse subject header node
  $subjectHeaderNode = $detailNode->find('.subject_header');
  $name = trim($subjectHeaderNode->text);

  // retrieve petition number and type
  list($number, $type) = getPetitionNumberAndTypeFromName($name);
  if ($number === null) {
    trigger_error(sprintf(
      'Unexpected petition name "%s" for petition id %d.',
      $url, $id
    ), E_USER_WARNING);
    return null;
  }

  // retrieve title
  $title = null;

  try {
    // title nodes always begin with '- '
    $titleNode = $subjectHeaderNode->nextSibling();
    $title = substr(trim($titleNode->text), 2);
  } catch (Exception $e) {
    // unexpected html
    trigger_error(sprintf(
      'Unexpected html, no title found for petition id %d.',
      $id
    ), E_USER_WARNING);
    return null;
  }

  trigger_error(sprintf(
    'Found %s petition Nr %d: "%s".',
    $type, $number, $title
  ), E_USER_NOTICE);

  // interpret author
  $author = ucwords(strtolower($raw['auteur']));
  $authorRole = isset($raw['en qualité de']) ? $raw['en qualité de'] : null;
  $authorOrganisation = isset($raw['association']) ? $raw['association'] : null;

  // interpret submission date
  $submissionDate = date('Y-m-d', strtotime($raw['dépôt']));

  // interpret status
  $statusMap = [
    'En attente de validation' => 'validating',
    'En examen de recevabilité' => 'validating',
    'Irrecevable' => 'rejected',
    'Recevable' => 'approved',
    'Recevable (reclassée: seuil non atteint)' => 'approved',
    'En cours de signature' => 'open',
    'Seuil atteint' => 'quorum_reached',
    'Seuil non atteint' => 'quorum_not_reached',
    'Reclassée' => 'reclassified',
    'Clôturée' => 'closed',
  ];

  $status = 'unexpected';
  $rawStatus = $raw['status'];
  if (isset($statusMap[$rawStatus])) {
    $status = $statusMap[$rawStatus];
  }

  $reclassifiedId = null;
  if ($raw['status'] === 'Recevable (reclassée: seuil non atteint)') {
    // check for link to original petition
    $anchorNodes = $detailNode->find('.panel_div a');
    if (count($anchorNodes) > 0) {
      $reclassifiedUrl = $anchorNodes[0]->href;
      $reclassifiedId = getPetitionIdFromUrl($reclassifiedUrl);
    }
  }

  unset($document);

  // interpret signatures
  $signaturesElectronic =
    isset($raw['signatures électroniques']) &&
    is_numeric($raw['signatures électroniques'])
    ? (int) $raw['signatures électroniques']
    : null;

  $signaturesPaper =
    isset($raw['signatures papier']) &&
    is_numeric($raw['signatures papier'])
    ? (int) $raw['signatures papier']
    : null;

  $signatures =
    isset($raw['total des signatures']) &&
    is_numeric($raw['total des signatures'])
    ? (int) $raw['total des signatures']
    : (
      $signaturesElectronic === null && $signaturesPaper === null
      ? null
      : ($signaturesElectronic !== null ? $signaturesElectronic : 0) +
        ($signaturesPaper !== null ? $signaturesPaper : 0)
    );

  $signatureMap = null;
  if ($fetchDetail && $signaturesElectronic !== null) {
    trigger_error(sprintf(
      'Found %d electronic signatures.',
      $signaturesElectronic),
    E_USER_NOTICE);

    $signatureMap = fetchSignatureMapWithId($id);
  }

  // compose data
  $petition = [
    'id' => $id,
    'reclassifiedId' => $reclassifiedId,
    'number' => $number,
    'type' => $type,
    'status' => $status,
    'submissionDate' => $submissionDate,
    'title' => $title,
    'author' => $author,
    'authorRole' => $authorRole,
    'authorOrganisation' => $authorOrganisation,
    'signatures' => $signatures,
    'signaturesElectronic' => $signaturesElectronic,
    'signaturesPaper' => $signaturesPaper,
    'signatureMap' => $signatureMap,
    'url' => $url,
  ];

  return $petition;
}

function fetchSignatureMapWithId($id) {
  global $config, $postCodeCantonData;

  if (!$postCodeCantonData) {
    // load post code canton data
    $json = file_get_contents($config['postCodeCantonFilename']);
    $postCodeCantonData = json_decode($json, true);
    unset($json);
  }

  $cantonCodeNameMap = $postCodeCantonData['cantons'];
  $postCodeCantonMap = $postCodeCantonData['postCodes'];

  $cantonCountMap = [];
  $hiddenCount = 0;
  $invalidCount = 0;

  $page = 0;

  do {
    $page ++;
    trigger_error(sprintf(
      'Fetching signature page %d...',
      $page),
    E_USER_NOTICE);

    // retrieve next signature page
    $url = sprintf(
      $config['petitionSignaturePageUrl'],
      $config['chamberToken'],
      $id,
      $page);

    $document = fetchDocument($url);

    // collect entries
    $rows = $document->find('#PRINT_DEBAT_DETAILS .table_column_content');
    foreach ($rows as $row) {
      $columns = $row->find('td');

      if (count($columns) === 4) {
        // count signatures for each post code
        $postCode = $columns[3]->text;

        // remove all non-numeric characters
        $postCode = preg_replace('/[^0-9]/', '', $postCode);

        if (isset($postCodeCantonMap[$postCode])) {
          $canton = $postCodeCantonMap[$postCode];
          $cantonCountMap[$canton] =
            isset($cantonCountMap[$canton])
            ? $cantonCountMap[$canton] + 1
            : 1;
        } else {
          $invalidCount ++;
        }

      } else {
        // consider this signature to be hidden
        // 'Coordonnées non publiées'
        $hiddenCount ++;
      }
    }

    // continue as long as there are entries
  } while (count($rows) > 0);

  $cantonNameCountMap = [];
  foreach ($cantonCodeNameMap as $code => $name) {
    $cantonNameCountMap[$name] =
      isset($cantonCountMap[$code])
      ? $cantonCountMap[$code]
      : 0;
  }

  $cantonNameCountMap['other'] = $invalidCount;

  return [
    'hiddenSignatures' => $hiddenCount,
    'cantons' => $cantonNameCountMap,
  ];
}

function fetchDocument($url) {
  global $memcached, $dom;

  // use url hash as cache key
  $key = hash('sha256', $url, false);

  // try to retrieve page html from cache
  $html = $memcached->get($key);

  if ($html === false) {
    // page html is not in cache
    // fetch page and cache html
    $html = file_get_contents($url);
    $memcached->set($key, $html, time() + 24 * 60 * 60);
  }

  // parse html
  $dom->load($html);

  return $dom;
}

function getPetitionIdFromUrl($url)
{
  // get id parameter from url
  $matches = [];
  if (preg_match('/[&|\?]id=([0-9]+)/', $url, $matches) === 1) {
    return (int) $matches[1];
  }

  // unvalid petition url, there is no id parameter
  return null;
}

function getPetitionNumberAndTypeFromName($name)
{
  $matches = [];
  $result = preg_match(
    '/^pétition (.+) n°([0-9]+)$/',
    mb_strtolower($name),
    $matches);

  if ($result !== 1) {
    // unexpected petition name
    return [null, null];
  }

  $number = (int) $matches[2];
  $rawType = $matches[1];

  $typeMap = [
    'ordinaire' => 'regular',
    'publique' => 'public',
  ];

  $type = 'unexpected';
  if (isset($typeMap[$rawType])) {
    $type = $typeMap[$rawType];
  }

  return [$number, $type];
}
