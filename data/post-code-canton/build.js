
// this script compiles the CACLR bundle to a JSON file
// mapping luxembourgish post codes to cantons
// dataset: https://data.public.lu/en/datasets/registre-national-des-localites-et-des-rues/

var fs = require('fs');

var config = {
  exportFilename: 'post-code-canton.json',
  codeptFilename: 'CACLR/CODEPT',
  localiteFilename: 'CACLR/LOCALITE',
  cantonFilename: 'CACLR/CANTON'
};

// read data
var codeptData = fs.readFileSync(config.codeptFilename);
var localiteData = fs.readFileSync(config.localiteFilename);
var cantonData = fs.readFileSync(config.cantonFilename);
buildMap(codeptData, localiteData, cantonData);

function buildMap(codeptData, localiteData, cantonData) {

  var postCodeLocationMap = buildPostCodeLocationMap(codeptData);
  var locationCantonMap = buildLocationCantonMap(localiteData);
  var cantonNameMap = buildCantonNameMap(cantonData);

  var postCodeCantonMap = {};
  var postCode;

  var canton = null;

  for (postCode in postCodeLocationMap) {
    var location = postCodeLocationMap[postCode];

    // try to find location in location canton map
    if (locationCantonMap[location] !== undefined) {
      // found match! map them together
      canton = locationCantonMap[location];

      if (cantonNameMap[canton] !== undefined) {
        postCodeCantonMap[postCode] = canton;
      } else {
        console.log(
          'Unexpected canton number ' + canton + ' for location ' +
          postCode + ' ' + location + '. Ignore.');
      }

    } else {
      // location can't be matched, but there is a high chance
      // of being the same canton as the last one
      console.log(
        'Can\'t match location ' + postCode + ' ' + location + ' to canton. ' +
        'Using previous canton.');
      postCodeCantonMap[postCode] = canton;
    }
  }

  var data = {
    cantons: cantonNameMap,
    postCodes: postCodeCantonMap
  };

  var json = JSON.stringify(data);

  // write to file
  fs.writeFile(config.exportFilename, json, function(err) {
    if (err) { throw err; }
    console.log('Data saved to ' + config.exportFilename);
  });
}

function buildPostCodeLocationMap(data) {
  var map = {};
  var pattern = new RegExp('^([0-9]{4})([^ ]+)', 'gm');
  var matches;

  while (matches = pattern.exec(data)) {
    var postCode = matches[1];
    var location = matches[2].replace(/\W/g, '').toLowerCase();
    map[postCode] = location;
  }

  return map;
}

function buildLocationCantonMap(data) {
  var map = {};
  var pattern = new RegExp('^.{45}(.{40}).{24}([0-9]{2})', 'gm');
  var matches;

  while (matches = pattern.exec(data)) {
    var location = matches[1].replace(/\W/g, '').toLowerCase();
    var canton = parseInt(matches[2]);
    map[location] = canton;
  }

  return map;
}

function buildCantonNameMap(data) {
  var map = {};
  var pattern = new RegExp('^([0-9]{2})([^ ]+)', 'gm');
  var matches;

  while (matches = pattern.exec(data)) {
    var canton = parseInt(matches[1]);
    var name = matches[2].toLowerCase();
    map[canton] = name;
  }

  return map;
}
