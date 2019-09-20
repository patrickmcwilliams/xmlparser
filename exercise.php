<?php

/**
 * Class XMLParser
 * From a PHP.net comment on https://secure.php.net/manual/en/function.xml-parse-into-struct.php
 * Depends on the PHP-XML extension that's natively bundled with PHP.
 */
class XMLParser
{
    // raw xml
    private $rawXML;
    // xml parser
    private $parser = null;
    // array returned by the xml parser
    private $valueArray = array();
    private $keyArray = array();

    // arrays for dealing with duplicate keys
    private $duplicateKeys = array();

    // return data
    private $output = array();
    private $status;

    public function __construct($xmlFileNameLocation)
    {
        $this->rawXML = file_get_contents($xmlFileNameLocation);
        $this->parser = xml_parser_create();

        return $this->parse();
    }

    private function parse()
    {

        $parser = $this->parser;

        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0); // Dont mess with my cAsE sEtTings
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);     // Dont bother with empty info
        if (!xml_parse_into_struct($parser, $this->rawXML, $this->valueArray, $this->keyArray)) {
            $this->status = 'error: ' . xml_error_string(xml_get_error_code($parser)) . ' at line ' . xml_get_current_line_number($parser);

            return false;
        }
        xml_parser_free($parser);

        $this->findDuplicateKeys();

        // tmp array used for stacking
        $stack = array();
        $increment = 0;

        foreach ($this->valueArray as $val) {
            if ($val['type'] == "open") {
                //if array key is duplicate then send in increment
                if (array_key_exists($val['tag'], $this->duplicateKeys)) {
                    array_push($stack, $this->duplicateKeys[$val['tag']]);
                    $this->duplicateKeys[$val['tag']]++;
                } else {
                    // else send in tag
                    array_push($stack, $val['tag']);
                }
            } elseif ($val['type'] == "close") {
                array_pop($stack);
                // reset the increment if they tag does not exists in the stack
                if (array_key_exists($val['tag'], $stack)) {
                    $this->duplicateKeys[$val['tag']] = 0;
                }
            } elseif ($val['type'] == "complete") {
                //if array key is duplicate then send in increment
                if (array_key_exists($val['tag'], $this->duplicateKeys)) {
                    array_push($stack, $this->duplicateKeys[$val['tag']]);
                    $this->duplicateKeys[$val['tag']]++;
                } else {
                    // else send in tag
                    array_push($stack, $val['tag']);
                }

                $this->setArrayValue($this->output, $stack, array_key_exists('value', $val) ? $val['value'] : "");
                array_pop($stack);
            }
            $increment++;
        }

        $this->status = 'success: xml was parsed';

        return true;

    }

    private function findDuplicateKeys()
    {
        for ($i = 0; $i < count($this->valueArray); $i++) {
            // duplicate keys are when two complete tags are side by side
            if ($this->valueArray[$i]['type'] == "complete") {
                if ($i + 1 < count($this->valueArray)) {
                    if ($this->valueArray[$i + 1]['tag'] == $this->valueArray[$i]['tag'] && $this->valueArray[$i + 1]['type'] == "complete") {
                        $this->duplicateKeys[$this->valueArray[$i]['tag']] = 0;
                    }
                }
            }
            // also when a close tag is before an open tag and the tags are the same
            if ($this->valueArray[$i]['type'] == "close") {
                if ($i + 1 < count($this->valueArray)) {
                    if ($this->valueArray[$i + 1]['type'] == "open" && $this->valueArray[$i + 1]['tag'] == $this->valueArray[$i]['tag']) {
                        $this->duplicateKeys[$this->valueArray[$i]['tag']] = 0;
                    }
                }
            }
        }
    }

    private function setArrayValue(&$array, $stack, $value)
    {
        if ($stack) {
            $key = array_shift($stack);
            $this->setArrayValue($array[$key], $stack, $value);

            return $array;
        } else {
            $array = $value;
        }
    }

    public function getOutput()
    {
        return $this->output;
    }

}

/*
*   RestaurantDataUtil
*
*   New Class to transform XMLParser object into properly formatted object
*/
class RestaurantDataUtil {
    // Constant to map values to days
    const DAYS = array(
        "Sun",
        "Mon",
        "Tue",
        "Wed",
        "Thu",
        "Fri",
        "Sat",
    );

    /*
    *   Prints restaurant data
    *
    *   @param array $restaurants data parsed from XML as array
    *   @return void
    */
    public static function printData($restaurants, $outFile){
        // Map data from transformed restaurant data to desired output
        $transformedRestaurantData = array_map(function($data){
            $flattenedSchedule = "";
            $flattenedScheduleArray = array();
            $scheduleArray = $data["schedule"];

            // Map times from restaurant schedule to desired output
            $flattenedScheduleArray = array_map(function($times) use($scheduleArray){
                // get matching days for each matching time slot
                $daysForTimes = array_keys($scheduleArray, $times);
                
                // build schedule string by matching days and times
                $daySchedule = array_reduce($daysForTimes, function($out, $day){
                    // init return value
                    if($out["out"] == ""){
                        $out["out"] = $day;
                    }
                    // if previous day matches current, replace last day for matching time slot
                    elseif(self::DAYS[array_search($out["last"], self::DAYS)+1] == $day){
                        $pattern = '/^([^;-]*)(?:[^;]*)$/';
                        $replacement = '$1-'.$day;
                        $out["out"] = preg_replace($pattern, $replacement, $out["out"]);
                    }
                    // if prior day was not contiguous with current day, add new slot
                    else{
                        $out["out"] .= ", ".$day;
                    }
                    $out["last"] = $day;
                    return $out;
                }, array("out"=>"","last"=>""));
                
                // build final day schedule string
                return $scheduleString = $daySchedule["out"].": ".$times;

            }, $scheduleArray);
            
            // build final restaurant schedule string
            $flattenedSchedule = join("; ", array_unique($flattenedScheduleArray));
            $outputString = "#".$data["id"].": ".$data["name"]." | ".$flattenedSchedule;

            print_r($outputString);
            return $outputString;
        },self::transformRestaurantData($restaurants));

        // write results to file
        $file = fopen($outFile,"w");
        fwrite($file, join("\n", $transformedRestaurantData));
        fclose($file);
    }

    private static function transformRestaurantData($restaurants){
        // map data to desired output
        $transformedRestaurantDataArray = array_map(function($restaurant){
            $id = $restaurant["biz_id"];
            $name = $restaurant["biz_name"];
            
            // get transformed data for schedule
            $transformedScheduleArray = self::transormScheduleData($restaurant["schedule"]);

            return array("id" => $id, "name" => $name, "schedule" => $transformedScheduleArray);
        }, $restaurants["response"]["data"]);

        return $transformedRestaurantDataArray;
    }

    private static function transormScheduleData($schedule){
        $outArray = array();
        $transformedSchedule = array_map(function($day){
            $open = $day["open"];
            $close = $day["close"];
            $day = self::DAYS[intval($open["day"])];
            $startTime = self::convertTime($open["time"]);
            $endTime = self::convertTime($close["time"]);
            $times = $startTime."-".$endTime;
            $dayArray = array($day => $times);
            return $dayArray;
        
        }, $schedule);

        // merge like days with split times
        foreach ($transformedSchedule as $day) {
            $key = key($day);
            $val = $day[key($day)];
            if (array_key_exists($key, $outArray)){
                $outArray[$key] = $outArray[$key]." & ".$val; 
            }
            else{
                $outArray[$key] = $val; 
            }
        }
        return $outArray;
    }

    private static function convertTime($time){
        return preg_replace("/^0|:00/", "", date('h:ia', strtotime($time)));
    }
}

$parser = new XMLParser("times.xml");
$restaurants = $parser->getOutput();
RestaurantDataUtil::printData($restaurants, "results.txt");

?>