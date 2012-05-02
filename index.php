<?php
/**
 * @package EnvCanadaWeather
 * @version 0.3
 */
/*
Plugin Name: Environment Canada Weather widgets
Plugin URI: http://wordpress.org/extend/plugins/envcanadaweather/
Description: Add various bits of current weather data from Environment Canada's XML weather service. No forecast data yet, but you've got everything else. To find your city code, go to http://dd.weatheroffice.ec.gc.ca/citypage_weather/docs/site_list_provinces_en.html
Author: Paul d'Aoust
Version: 0.3
Author URI: http://heliosstudio.ca
*/

global $wpdb;
global $envcanadaweather_cacheTableName, $envcanadaweather_warningsTableName, $envcanadaweather_forecastsTableName, $envcanadaweather_forecastsWindsTableName, $envcanadaweather_cache;
$envcanadaweather_cacheTableName = $wpdb->prefix."envcanadaweather_cache";
$envcanadaweather_warningsTableName = $wpdb->prefix."envcanadaweather_warnings";
$envcanadaweather_forecastsTableName = $wpdb->prefix."envcanadaweather_forecasts";
$envcanadaweather_forecastsWindsTableName = $wpdb->prefix."envcanadaweather_forecasts_winds"; $envcanadaweather_cache = array();

class EnvCanadaWeather {
	/* $weatherData is just a hashed cache; keys are city codes and
	   values are an array of fields */
	private static $weatherData = array ();
	private static $defaults = array (
		'province' => 'BC',
		'citycode' => 's0000772', // Penticton
		'updateInterval' => 3600 // one hour
	);
	private static $dbVersion = '0.7';
	/* acceptable fields and type hint(s) for each; the first in the
	 * array is the default hint. Note: for time data, 'string' is an
	 * alias for 'datetime' */
	private static $fieldHints = array (
		'province' => array('string'),
		'citycode' => array('string'),
		'timestamp' => array('timestamp', 'string', 'datetime', 'date', 'time'),
		'dateTime' => array('timestamp', 'string', 'datetime', 'date', 'time'),
		'lat' => array('float', 'int'),
		'lon' => array('float', 'int'),
		'city' => array('string'),
		'region' => array('string'),
		'stationCode' => array('string'),
		'stationLat' => array('float', 'int'),
		'stationLon' => array('float', 'int'),
		'observationDateTime' => array('timestamp', 'string', 'datetime', 'date', 'time'),
		'condition' => array('string'),
		'iconCode' => array('int', 'string'),
		'temperature' => array('float', 'int'),
		'dewpoint' => array('float', 'int'),
		'pressure' => array('float', 'int'),
		'pressureChange' => array('float', 'int'),
		'pressureTendency' => array('string'),
		'visibility' => array('float', 'int'),
		'relativeHumidity' => array('float', 'int'),
		'humidex' => array('float', 'int'),
		'windSpeed' => array('float', 'int'),
		'windGust' => array('float', 'int'),
		'windDirection' => array('string'),
		'windBearing' => array('int'),
		'windChill' => array('float', 'int'),
		'warningsURL' => array('string'),
		'warnings' => array('array',
			'children' => array(
				'type' => array('string'),
				'priority' => array('string'),
				'description' => array('string'),
				'eventIssueDateTime' => array('timestamp', 'string', 'datetime', 'date', 'time')
			),
			'table' => null,
		),
		'regionalNormalsLow' => array('float', 'int'),
		'regionalNormalsHigh' => array('float', 'int'),
		'forecastIssueDateTime' => array('timestamp', 'string', 'datetime', 'date', 'time'),
		'forecasts' => array('array',
			'children' => array(
				'period' => array('string'),
				'periodLong' => array('string'),
				'dateTime' => array('timestamp', 'string', 'datetime', 'date', 'time'),
				'textSummary' => array('string'),
				'cloudPrecipTextSummary' => array('string'),
				'iconCode' => array('int', 'string'),
				'pop' => array('float', 'int'),
				'abbreviatedTextSummary' => array('string'),
				'temperatureLow' => array('float', 'int'),
				'temperatureHigh' => array('float', 'int'),
				'windTextSummary' => array('string'),
				'winds' => array ('array',
					'children' => array(
						'index' => array('int'),
						'rank' => array('string'),
						'windSpeed' => array('float', 'int'),
						'windGust' => array('float', 'int'),
						'windDirection' => array('string'),
						'windBearing' => array('float', 'int'),
					),
					'table' => null,
					'primaryKey' => 'index',
					'foreignKey' => 'period',
					'aggregate' => array (
						'windSpeedLowest' => array('float', 'int',
							'field' => 'windSpeed',
							'criteria' => 'min'
						),
						'windSpeedHighest' => array('float', 'int',
							'field' => 'windSpeed',
							'criteria' => 'max'
						),
						'windSpeedMinMax' => array('float', 'int',
							'field' => 'windSpeed',
							'criteria' => 'minmax'
						),
						'windGustLowest' => array('float', 'int',
							'field' => 'windGust',
							'criteria' => 'min'
						),
						'windGustHighest' => array('float', 'int',
							'field' => 'windGust',
							'criteria' => 'max'
						),
						'windGustMinMax' => array('float', 'int',
							'field' => 'windGust',
							'criteria' => 'minmax'
						)
					)
				),
				'precipitationTextSummary' => array('string'),
				'precipType' => array('string'),
				'precipitationAccumulationType' => array('string'),
				'precipitationAmount' => array('float', 'int'),
				'uvIndex' => array('int'),
				'uvCategory' => array('string'),
				'uvTextSummary' => array('string'),
				'relativeHumidity' => array('float', 'int'),
				'comfort' => array('string'),
				'frost' => array('string'),
				'snowLevelTextSummary' => array('string')
			),
			'table' => null,
			'primaryKey' => 'period'
		),
		'yesterdayTemperatureLow' => array('float', 'int'),
		'yesterdayTemperatureHigh' => array('float', 'int'),
		'yesterdayPrecip' => array('float', 'int')
	);
	private static $cityCodes = array (
	);
	/* a list of short descriptions mapped to icon codes; useful for
	   CSS hooks */
	private static $iconCodes = array (
		'sunny',						// 00
		'mainlysunny',					// 01
		'partlycloudy',					// 02
		'mostlycloudy',					// 03
		'increasingcloud',				// 04
		'decreasingcloud',				// 05
		'lightrain',					// 06
		'lightrainsnow',				// 07
		'lightsnow',					// 08
		'thunderstorm',					// 09
		'cloudy',						// 10
		'lightrain',					// 11
		'rain',							// 12
		'heavyrain',					// 13
		'freezingrain',					// 14
		'rainsnow',						// 15
		'lightsnow',					// 16
		'snow',							// 17
		'heavysnow',					// 18
		'thunderstormprecipitation',	// 19
		'none', 'none', 'none',			// 20, 21, 22
		'haze',							// 23
		'fog',							// 24
		'driftingsnow',					// 25
		'crystals',						// 26
		'hail',							// 27
		'drizzle',						// 28
		'none',							// 29
		'clearnight',					// 30
		'mainlyclearnight',				// 31
		'partlycloudynight',			// 32
		'mostlycloudynight',			// 33
		'increasingcloudnight',			// 34
		'decreasingcloudnight',			// 35
		'lightrainnight',				// 36
		'lightrainsnownight',			// 37
		'lightsnownight',				// 38
		'thunderstormnight',			// 39
		'blowingsnow',					// 40
		'funnelcloud',					// 41
		'tornado',						// 42
		'none',							// 43
		'smoke',						// 44
		'dust'							// 45
	);

	/* set up cache table in database, and insert default city, prov,
	 * and update interval into wp_config table if they don't exist */
	public function install () {
		global $wpdb, $envcanadaweather_cacheTableName, $envcanadaweather_warningsTableName, $envcanadaweather_forecastsTableName, $envcanadaweather_forecastsWindsTableName;
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		$q = "DROP TABLE $envcanadaweather_cacheTableName, $envcanadaweather_warningsTableName, $envcanadaweather_forecastsTableName, $envcanadaweather_forecastsWindsTableName";
		$wpdb->query($q);
		$q = "CREATE TABLE $envcanadaweather_cacheTableName ("
			."	province VARCHAR(2),"
			."	citycode VARCHAR(8),"
			."	`timestamp` DATETIME,"
			."	`dateTime` DATETIME,"
			."	lat DECIMAL(5,2),"
			."	lon DECIMAL(5,2),"
			."	city VARCHAR(50),"
			."	region VARCHAR(255),"
			."	stationCode VARCHAR(50),"
			."	stationLat DECIMAL(5,2),"
			."	stationLon DECIMAL(5,2),"
			."	observationDateTime DATETIME,"
			."	`condition` VARCHAR(150),"
			."	iconCode SMALLINT(3) UNSIGNED,"
			."	temperature DECIMAL(5,2),"
			."	dewpoint DECIMAL(5,2),"
			."	pressure DECIMAL(5,2),"
			."	pressureChange DECIMAL(5,2),"
			."	pressureTendency VARCHAR(15),"
			."	visibility DECIMAL(5,2),"
			."	relativeHumidity DECIMAL(5,2),"
			."	humidex DECIMAL(5,2),"
			."	windSpeed DECIMAL(5,2),"
			."  windGust DECIMAL(5,2),"
			."	windDirection ENUM('','N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SSO','SW','SO','WSW','OSO','W','O','WNW','ONO','NW','NO','NNW','NNO','VR'),"
			."	windBearing SMALLINT(3),"
			."	windChill DECIMAL(5,2),"
			."	warningsURL VARCHAR(255),"
			."	forecastIssueDateTime DATETIME,"
			."	regionalNormalsLow DECIMAL(5,2),"
			."	regionalNormalsHigh DECIMAL(5,2),"
			."	yesterdayTemperatureLow DECIMAL(5,2),"
			."	yesterdayTemperatureHigh DECIMAL(5,2),"
			."	yesterdayPrecip DECIMAL(5,2),"
			."	PRIMARY KEY  (province, citycode)"
			.");";
		dbDelta($q); // WordPress' built-in database upgrade function
		$q = "CREATE TABLE $envcanadaweather_warningsTableName ("
			."	citycode VARCHAR(8),"
			."	type ENUM('', 'advisory', 'warning', 'watch', 'ended'),"
			."	priority ENUM('', 'low', 'medium', 'high', 'urgent'),"
			."	description VARCHAR(50),"
			."	eventIssueDateTime DATETIME"
			.");";
		dbDelta($q); // WordPress' built-in database upgrade function
		$q = "CREATE TABLE $envcanadaweather_forecastsTableName ("
			."	citycode VARCHAR(8),"
			."	period VARCHAR(12),"
			."	periodLong VARCHAR(12),"
			."	`dateTime` DATETIME,"
			."	textSummary VARCHAR(500),"
			."	cloudPrecipTextSummary VARCHAR(500),"
			."	iconCode SMALLINT(3) UNSIGNED,"
			."	pop DECIMAL(5,2),"
			."	abbreviatedTextSummary VARCHAR(255),"
			."	temperatureLow DECIMAL(5,2),"
			."	temperatureHigh DECIMAL(5,2),"
			."	windTextSummary VARCHAR(255),"
			."	precipitationTextSummary VARCHAR(255),"
			."	precipType VARCHAR(20),"
			."	precipitationAccumulationType VARCHAR(20),"
			."	precipitationAmount DECIMAL(5,2),"
			."	uvIndex SMALLINT(3) UNSIGNED,"
			."	uvCategory VARCHAR(20),"
			."	uvTextSummary VARCHAR(255),"
			."	relativeHumidity DECIMAL(5,2),"
			."	comfort VARCHAR(255),"
			."	frost VARCHAR(255),"
			."	snowLevelTextSummary VARCHAR(255),"
			."	PRIMARY KEY  (citycode, period)"
			.");";
		dbDelta($q); // WordPress' built-in database upgrade function
		$q = "CREATE TABLE $envcanadaweather_forecastsWindsTableName ("
			."	cityCode VARCHAR(8),"
			."	period VARCHAR(12),"
			."	`index` SMALLINT(3) UNSIGNED,"
			."	rank ENUM('', 'major', 'minor'),"
			."	windSpeed DECIMAL(5,2),"
			."	windGust DECIMAL(5,2),"
			."	windDirection ENUM('','N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SSO','SW','SO','WSW','OSO','W','O','WNW','ONO','NW','NO','NNW','NNO','VR'),"
			."	windBearing DECIMAL(5,2)"
			.");";
		dbDelta($q); // WordPress' built-in database upgrade function
		update_option('envcanadaweather_dbversion', self::$dbVersion);
		if (!get_site_option('envcanadaweather_defaultprovince')) {
			add_option('envcanadaweather_defaultprovince', self::$defaults['province']);
		}
		if (!get_site_option('envcanadaweather_defaultcitycode')) {
			add_option('envcanadaweather_defaultcitycode', self::$defaults['citycode']);
		}
		if (!get_site_option('envcanadaweather_updateinterval')) {
			add_option('envcanadaweather_updateinterval', self::$defaults['updateInterval']);
		}
	}

	public function activate () {
		global $envcanadaweather_warningsTableName, $envcanadaweather_forecastsTableName, $envcanadaweather_forecastsWindsTableName;
		// check installed table against this plugin's version
		if (get_site_option('envcanadaweather_dbversion') != self::$dbVersion) {
			self::install();
		}
		/* get location and update interval defaults for when none are
		 * specified by shortcode */
		self::$defaults['province'] = get_site_option('envcanadaweather_defaultprovince');
		self::$defaults['citycode'] = get_site_option('envcanadaweather_defaultcitycode');
		self::$defaults['updateinterval'] = get_site_option('envcanadaweather_updateinterval');
		self::$fieldHints['warnings']['table'] = $envcanadaweather_warningsTableName;
		self::$fieldHints['forecasts']['table'] = $envcanadaweather_forecastsTableName;
		self::$fieldHints['forecasts']['children']['winds']['table'] = $envcanadaweather_forecastsWindsTableName;
	}

	/* function for using in a template; convenience function for
	 * _getData() */
	public function getData (/* $field [,$format, [$citycode, $province]] */) {
		$attrs = array ();
		$args = func_get_args();
		foreach (array ('field', 'format', 'citycode', 'province') as $arg) {
			if (count($args)) {
				$attrs[$arg] = array_shift($args);
			}
		}
		return self::_getData($attrs);
	}

	/* return a certain piece of weather information; conforms to WP's
	 * shortcode interface by accepting an array of arguments:
	 *
	 * 		'field': the piece of information to return (see above
	 * 		$fields property for a list of valid fields)
	 *
	 * 		'format': an optional argument that parses the field in an
	 * 		alternate format to the one specified in $fields. Possible
	 * 		values:
	 * 			'float': convert a value to a float
	 * 			'int': make sure the value is not a float; rounds to
	 * 			the nearest integer
	 * 			'string': currently for icon codes only; converts an
	 * 			icon code to its corresponding hint (see above
	 * 			$iconCodes property)
	 *
	 * 		'citycode': an optional city code; if not specified, uses
	 * 		envcanadaweather_defaultcitycode from the wp_options table
	 *
	 * 		'province': required if 'citycode' is specified; helps us
	 * 		find the correct folder on Environment Canada's server.
	 * 		Really, this should be automatic, but it isn't yet :) */

	public function _getData ($attrs) {
		/*echo 'attrs ';
		print_r($attrs);
		echo ' of type '.gettype($attrs);*/
		global $wpdb, $envcanadaweather_cacheTableName, $envcanadaweather_warningsTableName, $envcanadaweather_forecastsTableName, $envcanadaweather_forecastsWindsTableName;
		// get defaults for city
		if (!isset($attrs['province'])) {
			$province = self::$defaults['province'];
		} else {
			$province = $attrs['province'];
		}
		if (!isset($attrs['citycode'])) {
			$citycode = self::$defaults['citycode'];
		} else {
			$citycode = $attrs['citycode'];
		}
		/*echo 'default citycode '.get_site_option('envcanadaweather_defaultcitycode');
		echo 'specified citycode'.$attrs['citycode'];
		echo 'province '.serialize($province).', citycode '.serialize($citycode);*/
		// create a key for the database (and self::$weatherData) cache
		$cacheKey = $province.':'.$citycode;
		// is the data cached in memory?
		if (isset(self::$weatherData[$cacheKey])) {
			// echo 'in cache';
			$weatherData = &self::$weatherData[$cacheKey];
		} else {
			// if it's not in memory, is it stored in the database?
			$citycodeEscaped = mysql_real_escape_string($citycode);
			$q = "SELECT *, UNIX_TIMESTAMP(`timestamp`) AS `timestamp`, UNIX_TIMESTAMP(observationDateTime) AS observationDateTime, UNIX_TIMESTAMP(forecastIssueDateTime) AS forecastIssueDateTime FROM $envcanadaweather_cacheTableName WHERE citycode = '$citycodeEscaped'";
			$weatherData = $wpdb->get_row($q, ARRAY_A);
			if ($weatherData) {
				// pull in warnings
				$q = "SELECT *, UNIX_TIMESTAMP(eventIssueDateTime) AS eventIssueDateTime FROM
				$envcanadaweather_warningsTableName WHERE citycode = '$citycodeEscaped'";
				$weatherData['warnings'] = $wpdb->get_results($q, ARRAY_A);
				// pull in forecasts
				$q = "SELECT *, UNIX_TIMESTAMP(a.`dateTime`) AS `dateTime`, a.period AS forecastPeriod, b.period AS windPeriod FROM $envcanadaweather_forecastsTableName a LEFT OUTER JOIN $envcanadaweather_forecastsWindsTableName b ON a.citycode = b.citycode AND a.period = b.period WHERE a.citycode = '$citycodeEscaped' ORDER BY a.`dateTime`, b.`index`";
				$weatherData['forecasts'] = array();
				if ($forecastsData = $wpdb->get_results($q, ARRAY_A)) {
					$windKeys = array (
						'rank' => null,
						'windSpeed' => null,
						'windGust' => null,
						'windDirection' => null,
						'windBearing' => null,
						'index' => null,
						'windPeriod' => null,
						'windDateTime' => null
					);
					foreach ($forecastsData as $forecastData) {
						// get and prune the wind data from the row
						$windData = array_intersect_key($forecastData, $windKeys);
						$windData['period'] = $windData['windPeriod'];
						unset($windData['windPeriod']);
						$forecastData = array_diff_key($forecastData, $windKeys);
						$forecastData['period'] = $forecastData['forecastPeriod'];
						unset($forecastData['forecastPeriod']);
						/* because we did a simple left join on the
						 * forecast table and its winds table without
						 * any grouping, we need to check to see if this
						 * forecast is already in the array */
						$period = $forecastData['period'];
						if (!isset($weatherData['forecasts'][$period])) {
							$weatherData['forecasts'][$period] = $forecastData;
							$thisForecast = &$weatherData['forecasts'][$period];
							$thisForecast['winds'] = array();
						}
						if ($windData['period']) {
							array_push($thisForecast['winds'], $windData);
						}
					}
				} // end if ($forecastsData = $wpdb->get_results(...))
				$cacheKey = $province.':'.$citycode;
				self::_aliasForecasts($weatherData['forecasts']);
				self::$weatherData[$cacheKey] = $weatherData;
			} // end if ($weatherData)
			// add data to memory cache
		}
		/* if not, or if the data is stale, let's prime both memory and
		 * db cache with fresh info */
		if (!$weatherData || (is_array($weatherData) && $weatherData['timestamp'] + self::$defaults['updateinterval'] < time())) {
			// echo 'not in db or cache expired; fetching new data';
			$weatherData = self::fetchData($province, $citycode);
		}
		if ($weatherData) {
			// and then output the data!
			$format = (isset($attrs['format']) ? $attrs['format'] : null);
			// print_r($weatherData);
			return self::_getDataRecursive($attrs['field'], $format, $weatherData, self::$fieldHints);
		}
	}

	private function _getDataRecursive ($fields, $format, $weatherData, $fieldHints) {
		if (is_string($fields)) {
			$fields = explode('/', $fields);
		}
		if (!is_array($fields) || !count($fields)) {
			return;
		}
		$field = array_shift($fields);
		// echo $field.' - ';
		// is this even a valid field?
		if (isset($fieldHints[$field])) {
			$fieldHint = $fieldHints[$field];
		} else {
			// echo 'no valid field';
			return;
		}
		// is this field included in the current data set?
		if (isset($weatherData[$field])) {
			$weatherDatum = $weatherData[$field];
		} else {
			return;
		}
		// is this node in the weather data an array? if so, get aggregate or dig deeper
		if (in_array('array', $fieldHint)) {
			$childField = array_shift($fields);
			if (!$childField) {
				if ($format == 'array') {
					return $weatherDatum;
				} else {
					return;
				}
			}
			if ((isset($fieldHint['children'][$childField]) || isset($fieldHint['aggregate'][$childField]))) {
				if (is_array($weatherDatum)) {
					/* look for aggregate hint; if none found, use the
					 * hint from 'children' key */
					$childFieldHint = $fieldHint['children'];
					/* Is this a special aggregate field that applies a
					 * function, like min or max, to another field? If
					 * so, we want to find out what field it performs
					 * the aggregate on */
					$childField = (isset($childFieldHint['field']) ? $childFieldHint['field'] : $childField);
					$childData = array();
					foreach ($weatherDatum as $k => $v) {
						$childData[$k] = self::_getDataRecursive($childField, $format, $v, $childFieldHint);
					}
					if (!count($childData)) {
						return;
					}
					// for aggregate fields, perform the aggregate func
					if (isset($childFieldHint['criteria'])) {
						switch ($childFieldHint['criteria']) {
							case 'min':
								return min($childData);
							case 'max':
								return max($childData);
							case 'minmax':
							case 'minMax':
								$min = min($childData);
								$max = max($childData);
								/* replaces entire child data array with
								 * min and max values, then breaks rather
								 * than returning, so format can be
								 * returned properly */
								$childData = array($min, $max);
								break;
							case 'avg':
								$count = count($childData);
								$sum = array_sum($childData);
								return $sum / $count;
							case 'sum':
								return array_sum($childData);
						}
					}
					switch ($format) {
						case 'array':
							return $childData;
						default:
							return implode(' to ', array_unique($childData));
					}
				} else {
					return;
				}
			} // end if (is_array($weatherDatum))
			// echo 'child key is '.$childKey;
			// echo implode(', ', array_keys($weatherDatum));
			if (!($weatherDatum = $weatherDatum[$childField])) {
				return;
			}
			return self::_getDataRecursive($fields, $format, $weatherDatum, $fieldHint['children']);
		}
		// fais gracefully when the specified format is not allowed
		if (!in_array($format, $fieldHint)) {
			$format = $fieldHint[0];
		}
		switch ($format) {
			case 'float':
				return (float) $weatherDatum;
			case 'int':
			case 'timestamp':
				/* still not decided on whether this should be round
				 * or floor -- round is more accurate, but it looks
				 * weird when you've got 29.6°C in one spot and 30°C
				 * in another */
				return round($weatherDatum);
			case 'string':
				/* get the icon code hint instead of the icon code
				 * itself */
				if ($field == 'iconCode') {
					return self::$iconCodes[(int) $weatherDatum];
				}
			// falls over into timestamp formatters
			case 'date':
			case 'time':
			case 'datetime':
				if (in_array($field, array('timestamp', 'dateTime')) || (strpos($field, 'DateTime') !== false)) {
					/* construct the date based on whether the date, time,
					 * or both ('string' or 'datetime') were asked for */
					$dateFormat = array();
					if (in_array($format, array('date', 'string'))) {
						$dateFormat[] = get_site_option('date_format');
					}
					if (in_array($format, array('time', 'string'))) {
						$dateFormat[] = get_site_option('time_format');
					}
					$dateFormat = implode(' ', $dateFormat);
					return date($dateFormat, $weatherDatum);
				}
			/* falls over into just spewing out the raw data for all
			 * other string-based data */
			default:
				return (string) $weatherDatum;
		}
	}

	// convenience function; prints out the result of getData();
	public function printData () {
		$attrs = array ();
		$args = func_get_args();
		foreach (array ('field', 'format', 'citycode', 'province') as $arg) {
			if (count($args)) {
				$attrs[$arg] = array_shift($args);
			}
		}
		return self::_printData($attrs);
	}

	public function _printData ($attrs) {
		echo self::_getData($attrs);
	}

	/* charge up the cache with data; should only be called when cache
	 * is either expired or empty for a given city */
	private function fetchData ($province, $citycode) {
		global $wpdb, $envcanadaweather_cacheTableName;
		$url = "http://dd.weatheroffice.ec.gc.ca/citypage_weather/xml/".urlencode($province)."/".urlencode($citycode)."_e.xml";
		$xml = file_get_contents($url);
		if (!$xml) {
			return false;
		}

		// get the most important bit; the currentConditions
		$weather = new SimpleXMLElement($xml);
		$current = $weather->currentConditions;
		$weatherData = array ();

		// get all the useful parts from the XML
		$weatherData['province'] = $province;
		$weatherData['citycode'] = $citycode;
		$weatherData['timestamp'] = time();
		foreach ($weather->dateTime as $dateTime) {
			if ($dateTime['zone'] == 'UTC') {
				$weatherData['dateTime'] = self::_convertTimeData($dateTime->textSummary);
			}
		}
		$weatherData['lat'] = self::_convertGeoData($weather->location->name['lat']);
		$weatherData['lon'] = self::_convertGeoData($weather->location->name['lon']);
		$weatherData['city'] = (string) $weather->location->name;
		$weatherData['region'] = (string) $weather->location->region;
		$weatherData['stationCode'] = (string) $current->station['code'];
		$weatherData['stationLat'] = self::_convertGeoData($current->station['lat']);
		$weatherData['stationLon'] = self::_convertGeoData($current->station['lon']);
		foreach ($current->dateTime as $dateTime) {
			if ($dateTime['zone'] == 'UTC') {
				$weatherData['observationDateTime'] = self::_convertTimeData($dateTime->textSummary);
			}
		}
		$weatherData['condition'] = strtolower($current->condition);
		$weatherData['iconCode'] = (int) $current->iconCode;
		$weatherData['temperature'] = (float) $current->temperature;
		$weatherData['dewpoint'] = (float) $current->dewpoint;
		$weatherData['pressure'] = (float) $current->pressure;
		$weatherData['pressureChange'] = (float) $current->pressure['change'];
		$weatherData['pressureTendency'] = (string) $current->pressure['tendency'];
		$weatherData['visibility'] = (float) $current->visibility;
		$weatherData['relativeHumidity'] = (float) $current->relativeHumidity;
		$weatherData['windSpeed'] = (float) $current->wind->speed;
		$weatherData['windGust'] = (float) $current->wind->gust;
		$weatherData['windDirection'] = (string) $current->wind->direction;
		$weatherData['windBearing'] = (float) $current->wind->bearing;

		// get warnings data
		$weatherData['warningsURL'] = (string) $weather->warnings['url'];
		$weatherData['warnings'] = array ();
		foreach ($weather->warnings->event as $event) {
			$wdEvent = array ();
			$wdEvent['type'] = (string) $event['type'];
			$wdEvent['priority'] = (string) $event['priority'];
			$wdEvent['description'] = (string) $event['description'];
			foreach ($event->dateTime as $dateTime) {
				if ($dateTime['zone'] == 'UTC') {
					$wdEvent['eventIssueDateTime'] = self::_convertTimeData($dateTime->textSummary);
				}
			}
			$weatherData['warnings'][] = $wdEvent;
		}

		// get forecast data
		$forecastGroup = $weather->forecastGroup;
		foreach ($forecastGroup->dateTime as $dateTime) {
			if ($dateTime['zone'] == 'UTC') {
				$weatherData['forecastIssueDateTime'] = self::_convertTimeData($dateTime->textSummary);
			}
		}
		$weatherData['regionalNormals'] = array ();
		foreach ($forecastGroup->regionalNormals->temperature as $temperature) {
			$weatherData['regionalNormals'.ucfirst($temperature['class'])] = (float) $temperature;
		}
		$weatherData['forecasts'] = array ();
		foreach ($forecastGroup->forecast as $forecast) {
			$wdForecast = array();
			$wdForecast['period'] = (string) $forecast->period['textForecastName'];
			$wdForecast['periodLong'] = (string) $forecast->period;
			$wdForecast['textSummary'] = (string) $forecast->textSummary;
			$wdForecast['cloudPrecipTextSummary'] = (string) $forecast->cloudPrecip->textSummary;
			$wdForecast['iconCode'] = (int) $forecast->abbreviatedForecast->iconCode;
			$wdForecast['pop'] = (float) $forecast->abbreviatedForecast->pop;
			$wdForecast['abbreviatedTextSummary'] = (string) $forecast->abbreviatedForecast->textSummary;
			foreach ($forecast->temperatures->temperature as $temperature) {
				$wdForecast['temperature'.ucfirst($temperature['class'])] = (float) $temperature;
			}
			$wdForecast['windTextSummary'] = (string) $forecast->winds->textSummary;
			$wdForecast['winds'] = array ();
			foreach ($forecast->winds->wind as $wind) {
				$wdWind = array ();
				$wdWind['index'] = (string) $wind['index'];
				$wdWind['rank'] = (string) $wind['rank'];
				$wdWind['windSpeed'] = (float) $wind->speed;
				$wdWind['windGust'] = (float) $wind->gust;
				$wdWind['windDirection'] = (string) $wind->direction;
				$wdWind['windBearing'] = (float) $wind->bearing;
				$wdForecast['winds'][(string) $wind['index']] = $wdWind;
			}
			/* precipitation -- left out precipType['start'] and ['end']
			 * cuz I honestly don't know what they're for and who would
			 * use them! */
			$wdForecast['precipitationTextSummary'] = (string) $forecast->precipitation->textSummary;
			$wdForecast['precipType'] = (string) $forecast->precipitation->precipType;
			$wdForecast['precipitationAccumulationType'] = (string) $forecast->precipitation->accumulation->name;
			$wdForecast['precipitationAmount'] = (float) $forecast->precipitation->accumulation->amount;
			$wdForecast['uvIndex'] = (int) $forecast->uv->index;
			$wdForecast['uvCategory'] = (string) $forecast->uv['category'];
			$wdForecast['uvTextSummary'] = (string) $forecast->uv->textSummary;
			$wdForecast['relativeHumidity'] = (float) $forecast->relativeHumidity;
			$wdForecast['comfort'] = (string) $forecast->comfort;
			$wdForecast['frost'] = (string) $forecast->comfort;
			$wdForecast['snowLevelTextSummary'] = (string) $forecast->snowLevel->textSummary;
			$weatherData['forecasts'][$wdForecast['period']] = $wdForecast;
		}
		self::_aliasForecasts($weatherData['forecasts']);

		// yesterday's conditions
		foreach ($weather->yesterdayConditions->temperature as $temperature) {
			$weatherData['yesterdayTemperature'.ucfirst($temperature['class'])] = (float) $temperature;
		}
		$weatherData['yesterdayPrecip'] = (float) $weather->yesterdayConditions->precip;

		// sunrise and sunset - not yet!

		// historical data - not yet!

		// add data to memory cache
		$cacheKey = $province.':'.$citycode;
		self::$weatherData[$cacheKey] = $weatherData;

		// add data to database cache
		self::_saveToDB($envcanadaweather_cacheTableName, array('citycode' => $citycode), array($weatherData), self::$fieldHints);
		return $weatherData;
	}

	private function _aliasForecasts (&$forecasts) {
		foreach ($forecasts as $period => &$forecast) {
			/* set up aliases so you can refer to forecasts in different
			 * ways. First, we'll alias to the lowercase period name */
			$forecasts[strtolower($period)] = &$forecast;
			/* change period to a string parseable by strtotime(), by
			 * changing 'night' to 18:00 */
			$periodActual = strtolower($period);
			if ($periodActual == 'tonight') {
				$periodActual = 'today night';
			}
			$periodActual = str_replace('night', '18:00', $periodActual);
			// e.g., if today is Wed, then 'day1' will be aliased to 'Thursday'
			$daysAhead = (strtotime($periodActual) - strtotime('today')) / 86400;
			if ($daysAhead == 1) {
				$forecasts['tomorrow'] = &$forecast;
				$forecasts['Tomorrow'] = &$forecast;
			}
			if ($daysAhead % 1) {
				/* 'day0.1' and 'day0.5' both refer to
				 * tonight; 'day1.1' and 'day1.5' refer
				 * to tomorrow night */
				$forecasts['day'.floor($daysAhead).'.1'] = &$forecast;
				$forecasts['day'.floor($daysAhead).'.5'] = &$forecast;
			} else {
				$forecasts['day'.floor($daysAhead)] = &$forecast;
			}
		}
		if (!isset($forecasts['today'])	) {
			/* if today's forecast has been superseded by tonight's, set
			 * up an alias so that tonight will be given instead*/
			$forecasts['today'] = &$forecasts['tonight'];
			$forecasts['Today'] = &$forecasts['tonight'];
			$forecasts['day0'] = &$forecasts['tonight'];
		}
	}

	private function _saveToDB($tableName, $extras, $rows, $fieldHints, $primaryKey = null, $foreignKeyCondition = null) {
		global $wpdb;
		$citycode = $extras['citycode'];
		$q = "DELETE FROM $tableName WHERE citycode = '".mysql_real_escape_string($citycode)."'";
		if ($foreignKeyCondition) {
			$q .= " AND ".$foreignKeyCondition;
		}
		$q .= ";\n";
		$wpdb->query($q);
		// empty set of rows is fine, but let's skip insert in that case
		if (is_array($rows) && count($rows)) {
			$q = "INSERT INTO $tableName ";
			$fields = array();
			$qRows = array();
			/* if these rows should have a primary key, keep track of
			 * duplicates -- caused by day-of-week aliases */
			if ($primaryKey) {
				$primaryKeys = array();
			}
			foreach ($rows as $row) {
				// skip if it's a duplicate
				if ($primaryKey) {
					if (in_array($row[$primaryKey], $primaryKeys)) {
						continue;
					} else {
						$primaryKeys[] = $row[$primaryKey];
					}
				}
				$qRow = array();
				foreach ($fieldHints as $fieldName => $fieldHint) {
					if (isset($row[$fieldName])) {
						$value = $row[$fieldName];
					} else if (isset($extras[$fieldName])) {
						/* $extras seems like a terrible kludge; it's
						 * used for keeping foreign keys which aren't
						 * normally in child arrays */
						$value = $extras[$fieldName];
					} else {
						$value = null;
					}
					switch ($fieldHint[0]) {
						case 'array':
							// we want an empty array, so we can purge rows
							if (!$value) {
								$value = array();
							}
							if (!isset($fieldHint['children']['citycode'])) {
								$fieldHint['children']['citycode'] = array('string');
							}
							if (in_array($fieldName, array('forecasts', 'winds'))) {
								if (!isset($fieldHint['children']['period'])) {
									$fieldHint['children']['period'] = array('string');
								}
								/* add foreign key to $extras so winds
								 * can have access to it. */
								$childExtras = ($fieldName == 'winds' ? array_merge($extras, array('period' => $row['period'])) : $extras);
							} else {
								$childExtras = $extras;
							}
							$primaryKeyChildren = (isset($fieldHint['primaryKey']) ? $fieldHint['primaryKey'] : null);
							if (isset($fieldHint['foreignKey']) && isset($row[$fieldHint['foreignKey']])) {
								$foreignKeyConditionChildren = '`'.$fieldHint['foreignKey'].'` = '.self::_sqlFormat($row[$fieldHint['foreignKey']]);
							} else {
								$foreignKeyConditionChildren = null;
							}
							self::_saveToDB($fieldHint['table'], $childExtras, $value, $fieldHint['children'], $primaryKeyChildren, $foreignKeyConditionChildren);
							break;
						default:
							if (!in_array($fieldName, $fields)) {
								$fields[] = $fieldName;
							}
							$qRow[] = self::_sqlFormat($value, $fieldHint);
					}
				}
				$qRows[] = '('.implode(',', $qRow).')';
			}
			$q .= ' (`'.implode('`,`', $fields).'`) VALUES ';
			$q .= implode(",\n", $qRows).";\n";
			$wpdb->query($q);
		}
	}

	/* converts native types (the usual string and numeric values, plus
	 * timestamp format) to MySQL-friendly format */
	private function _sqlFormat($value, $fieldHint = null) {
		if (is_null($value)) {
			return 'NULL';
		} else {
			if (!$fieldHint) {
				$fieldHint = 'string';
			} else if (is_array($fieldHint)) {
				$fieldHint = $fieldHint[0];
			}
			switch ($fieldHint) {
				case 'float':
					return (string) (float) $value;
				case 'int':
					return (string) (int) $value;
				case 'timestamp':
					return 'FROM_UNIXTIME('.$value.')';
				case 'string':
				default:
					return "'".mysql_real_escape_string($value)."'";
			}
		}
	}

	/* converts unsigned coordinates (with 'N', 'S', 'E', and 'W') into
	 * more standard signed coordinates */
	private function _convertGeoData ($coord) {
		$coord = str_split($coord);
		$direction = strtoupper(array_pop($coord));
		$coord = (float) implode('', $coord);
		if (in_array($direction, array('N', 'E'))) {
			return $coord;
		} else {
			return 0 - $coord;
		}
	}

	/* converts time data into UNIX timestamp */
	private function _convertTimeData ($datetime) {
		return strtotime(str_replace('at ', '', $datetime));
	}
}

register_activation_hook(__FILE__, array('EnvCanadaWeather', 'activate'));
add_action('plugins_loaded', array('EnvCanadaWeather', 'activate'));
add_shortcode('envcanadaweather_data', array('EnvCanadaWeather', '_getData'));
?>
