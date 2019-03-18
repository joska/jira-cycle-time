<?php
/**
 * @var array list of all project shortnames to create a report for
 */

$projects = ['JD'];

/**
* @var int number of months since resolution time (how far back do we want this report to generate)
*/
$issueAge = 6;

/**
 * @var string Name of the result file
 */
const FILE_NAME = 'issues.csv';

/**
 * @var string Name of the stats file
 */
const FILE_STATS_NAME = 'stats.csv';


/**
* @var [string] LowerCase statues names for the open/wip/closed superstates
*/
$statuses = [
    'open' => ['open','backlog','on hold','to do'],
    'wip' => ['ready for accept','estimate','code review','in progress'],
    'closed' => ['closed','cancelled','done'],
];

date_default_timezone_set("Europe/Prague");

$api = new JiraApi();
$worked = [];
$storyPointsFieldName = $api->getStoryPointsFieldName();

if (file_exists(FILE_NAME)){
    echo 'Results file exists. Removing.';
    unlink(FILE_NAME);
}
if (file_exists(FILE_STATS_NAME)){
    echo 'Stats file exists. Removing.';
    unlink(FILE_STATS_NAME);
}

writeToCsv(['Project','Points','CT Avg','CT Median','CT Min','CT Max'], FILE_STATS_NAME);
sort($projects);

foreach ($projects as $project){
    $stories = [];
    var_dump('Starting project '.$project);
    $issues = $api->getIssuesForProject($project, $issueAge);
    // $issues = ['PC-2339']; // TODO debug line, remove in production
    foreach ($issues as $issueName) {
        // var_dump('Processing '.$issueName);
        $issue = $api->getIssueByName($issueName);
        $cycleTime = $api->getTimeOnIssueInHours($issue, $statuses);

        // Write userstory to csv
        writeToCsv([
            $project,
            $issueName,
            ($issue->fields->$storyPointsFieldName ?? ''),
            $cycleTime], FILE_NAME
        );
        // Add to stats
        $stories[(string) ($issue->fields->$storyPointsFieldName ?? 'undefined')][] = $cycleTime;

        // var_dump('Adding '. $cycleTime. ' for issue '. $issueName . '. To category '.($issue->fields->$storyPointsFieldName ?? 'undefined'));
    }
    ksort($stories);


    // Compute stats
    foreach ($stories as $points => $times) {
        // var_dump($points);
        // var_dump($times);
        writeToCsv(
            [
                $project,
                $points, 
                ceil(average($times)),
                ceil(median($times)),
                min($times),
                max($times),
            ],
            FILE_STATS_NAME
        );
    }
}

function removeOutliers($dataset, $magnitude = 1) {

    $count = count($dataset);
    $mean = array_sum($dataset) / $count; // Calculate the mean
    $deviation = sqrt(array_sum(array_map("sdSquare", $dataset, array_fill(0, $count, $mean))) / $count) * $magnitude; // Calculate standard deviation and times by magnitude

    return array_filter($dataset, function($x) use ($mean, $deviation) { return ($x <= $mean + $deviation && $x >= $mean - $deviation); }); // Return filtered array of values that lie within $mean +- $deviation.
}

function sdSquare($x, $mean) {
    return pow($x - $mean, 2);
} 

function median($arr){
    if($arr){
        sort($arr);
        return $arr[round(count($arr) / 2) - 1];
    }
    return 0;
}
function average($arr){
    return ($arr) ? array_sum($arr)/count($arr) : 0;
}

function writeToCsv(array $line, string $fileName): void
{
    $line = implode(',', $line);
    $myfile = file_put_contents($fileName, $line.PHP_EOL , FILE_APPEND | LOCK_EX);
}

/**
 * JIRA class is responsible for REST api queries.
 */
class JiraApi
{
    public function getTimeOnIssueInHours($issue, array $statuses): int
    {
        $seconds = $this->getTimeOnIssueInSeconds($issue, $statuses);

        return ceil($seconds/3600);
    }

    public function getStoryPointsFieldName(): string
    {
        $url = 'http://jira.organisationdomain.com/rest/api/latest/field';
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode('john.doe@organisationdomain.com:superSecretPassword'),
                'Content-Type: application/json',
                'cache-control: no-cache',
                'Access-Control-Allow-Origin: *',
                ]
        ]);
        $resp = json_decode(curl_exec($curl));
        curl_close($curl);

        foreach($resp as $field){
            if ($field->name === 'Story Points'){
                return $field->id;
            }
        }

        throw new RuntimeException('No field with name Story Points exists.');
    }

    public function getTimeOnIssueInSeconds($issue, array $statuses): int
    {
        $statusWhiteList = array_merge($statuses['open'], $statuses['wip'],$statuses['closed']);

        $totalIssueTime = 0;
        $changelog = $issue->changelog->histories;
        $sortedLogs = [];
        foreach ($changelog as $log){
            foreach ($log->items as $item){
                if ($item->field == 'status'){
                    $time = strtotime($log->created);
                    if ($sortedLogs[$time]){
                        throw new LogicException('Log with the same timestamp already existst!');
                    }
                    $sortedLogs[$time] = [
                        'from' => strtolower($item->fromString),
                        'to' => strtolower($item->toString),
                    ]; 
                }
            }
        }
        ksort($sortedLogs);
        $workStarted = null;

        foreach ($sortedLogs as $time => $log)
        {
                if(
                    !in_array($log['from'], $statuses['wip']) &&
                    in_array($log['to'], $statuses['wip'])
                ){
                    // Work resumed/started
                    $workStarted = $time;
                }
                if(
                    in_array($log['from'], $statuses['wip']) &&
                    !in_array($log['to'], $statuses['wip']) &&
                    $workStarted //some old issues are beginning in the WIP status
                ){
                    // Work paused/ended
                    $workHours = $this->workHoursDifference($workStarted, $time);
                    $workStarted = null;

                }
                if (!in_array(strtolower($log['from']), $statusWhiteList)){
                    var_dump('Unknown status: '.$log['from']);
                }
                if (!in_array(strtolower($log['to']), $statusWhiteList)){
                    var_dump('Unknown status: '.$log['to']);
                }
                $totalIssueTime += $workHours;
        }

        return $totalIssueTime;
    }


    /**
     * Returns difference in workhours
     * https://stackoverflow.com/questions/13655722/php-subtract-dates-on-business-days-and-working-hours
     *
     * @param integer $date1 start date in seconds
     * @param integer $date2 end date in seconds
     * @return integer
     */
    public function workHoursDifference(int $date1, int $date2): int
    {
        if ($date1>$date2) { 
            $tmp=$date1; 
            $date1=$date2; 
            $date2=$tmp; 
            unset($tmp); 
            $sign=-1; 
        } else $sign = 1;
        if ($date1==$date2) return 0;

        $days = 0;

        // Working hours set up
        $working_days = array(1,2,3,4,5); // Monday-->Friday
        $working_hours = array(9, 17); // from 9:00 to 17:00 (8.0 hours)
        $current_date = $date1;

        $beg_h = floor($working_hours[0]); 
        $beg_m = ($working_hours[0]*60)%60;
        $end_h = floor($working_hours[1]); 
        $end_m = ($working_hours[1]*60)%60;

        //In case date1 is on same day of date2
        if (mktime(0,0,0,date('n', $date1), date('j', $date1), date('Y', $date1))==mktime(0,0,0,date('n', $date2), date('j', $date2), date('Y', $date2))) {
            //If its not working day, then return 0
            if (!in_array(date('w', $date1), $working_days)) return 0;

            $date0 = mktime($beg_h, $beg_m, 0, date('n', $date1), date('j', $date1), date('Y', $date1));
            $date3 = mktime($end_h, $end_m, 0, date('n', $date1), date('j', $date1), date('Y', $date1));

            if ($date1<$date0) {
                if ($date2<$date0) return 0;
                $date1 = $date0;
                if ($date2>$date3) $date2=$date3;
                return $date2-$date1;
            }
            if ($date1>$date3) return 0;
            if ($date2>$date3) $date2=$date3;
            return $date2-$date1;
        }

        //setup the very next first working time stamp
        if (!in_array(date('w',$current_date) , $working_days)) {
            // the current day is not a working day

            // the current time stamp is set at the beginning of the working day
            $current_date = mktime( $beg_h, $beg_m, 0, date('n',$current_date), date('j',$current_date), date('Y',$current_date) );

            // search for the next working day
            while ( !in_array(date('w',$current_date) , $working_days) ) {
                $current_date += 24*3600; // next day
            }
        } else {
            // check if the current timestamp is inside working hours
            $date0 = mktime( $beg_h, $beg_m, 0, date('n',$current_date), date('j',$current_date), date('Y',$current_date) );
            // it's before working hours, let's update it
            if ($current_date<$date0) $current_date = $date0;

            $date3 = mktime( $end_h, $end_m, 0, date('n',$current_date), date('j',$current_date), date('Y',$current_date) );

            if ($date3<$current_date) {
                // outch ! it's after working hours, let's find the next working day
                $current_date += 24*3600; // the day after
                // and set timestamp as the beginning of the working day
                $current_date = mktime( $beg_h, $beg_m, 0, date('n',$current_date), date('j',$current_date), date('Y',$current_date) );
                while ( !in_array(date('w',$current_date) , $working_days) ) {
                    $current_date += 24*3600; // next day
                }
            }
        }

        // so, $current_date is now the first working timestamp available...

        // calculate the number of seconds from current timestamp to the end of the working day
        $date0 = mktime( $end_h, $end_m, 0, date('n',$current_date), date('j',$current_date), date('Y',$current_date) );
        $seconds = $date0-$current_date;

        // calculate the number of days from the current day to the end day

        $date3 = mktime( $beg_h, $beg_m, 0, date('n',$date2), date('j',$date2), date('Y',$date2) );
        while ( $current_date < $date3 ) {
            if (in_array(date('w',$current_date) , $working_days) ) $days++; // it's a working day
            $current_date += 24*3600; // next day
        }
        $days--; //because we've already count the first day (in $seconds)

        // check if end's timestamp is inside working hours
        $date0 = mktime( $beg_h, $beg_m, 0, date('n',$date2), date('j',$date2), date('Y',$date2) );
        if ((!in_array(date('w', $date2), $working_days)) || ($date2 < $date0)) {
            // it's before, so nothing more !
        } else {
            // is it after ?
            $date3 = mktime( $end_h, $end_m, 0, date('n',$date2), date('j',$date2), date('Y',$date2) );
            if ($date2>$date3) $date2=$date3;
            // calculate the number of seconds from current timestamp to the final timestamp
            $tmp = $date2-$date0;
            $seconds += $tmp;
        }

        // calculate the working days in seconds
        $seconds += 3600*($working_hours[1]-$working_hours[0])*$days;

        return $sign * $seconds;
    }

    public function getIssueByName(string $issue)
    {
        $url = 'http://jira.organisationdomain.com/rest/api/latest/issue/%s?expand=changelog';
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_URL => sprintf($url,$issue),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode('john.doe@organisationdomain.com:superSecretPassword'),
                'Content-Type: application/json',
                'cache-control: no-cache',
                'Access-Control-Allow-Origin: *',
                ]
        ]);
        $resp = json_decode(curl_exec($curl));
        curl_close($curl);

        if (!$resp){
            throw new \RuntimeException(sprintf('Issue %s not found!', $issue));
        }

        return $resp;
    }
    
    public function getIssuesForProject(string $project, int $issueAge): array
    {
        $issues = [];
        $limit = 50;
        $offset = 0;
        $continue = true;

        do {
            $batch = $this->getIssueBatch($project, $issueAge, $limit, $offset);
            $issues = array_merge($issues, $batch);
            $offset += $limit;
            if(
                count($batch) < $limit ||
                $offset > 10000 // safety
            ){
                $continue = false;
            }
        } while ($continue);


        return $issues;
        
    }

    private function getIssueBatch(string $project, int $issueAge, int $limit, int $offset): array
    {
        // type = story AND statusCategory = Done AND status != cancelled AND resolved >= startOfMonth(-N)
        $url = 'http://jira.organisationdomain.com/rest/api/latest/search?jql=project%3D'.$project.'%20AND%20status%20!%3D%20cancelled%20AND%20type%3DStory%20AND%20statusCategory%3DDone%20AND%20resolved%20%3E%3D%20startOfMonth(-'.$issueAge.')&fields=key&maxResults='.$limit.'&startAt='.$offset;
        $curl = curl_init();

        // var_dump('Getting batch for offset '.$offset);
     
        // TODO refactor
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_URL => $url,
            CURLOPT_CONNECTTIMEOUT => 3600,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode('john.doe@organisationdomain.com:superSecretPassword'),
                'Content-Type: application/json',
                'cache-control: no-cache',
                'Access-Control-Allow-Origin: *',
                ]
        ]);
        $resp = json_decode(curl_exec($curl));
        curl_close($curl);
        
        if (!$resp){
            throw new RuntimeException(sprintf('No issues for project %s', $project));
        }
        
        $issueKeys = [];
        if ($resp->issues) {
            foreach($resp->issues as $issue){
                $issueKeys[] = $issue->key;
            }
        }

        return $issueKeys;
    }   
}